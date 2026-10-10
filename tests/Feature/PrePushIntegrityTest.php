<?php

namespace Tests\Feature;

use App\Actions\CreateTransferRequest;
use App\Actions\ReviewReservationRequest;
use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\ReservationRequest;
use App\Models\User;
use App\Models\Venue;
use App\Services\RegularScheduleConflictDetector;
use App\Services\RegularScheduleGenerator;
use App\Services\TeacherBookingAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PrePushIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 12:00');
        Notification::fake();
    }

    public function test_other_teachers_cannot_book_the_same_venue_at_the_same_time(): void
    {
        $existing = LessonSlot::factory()->create();
        ReservationRequest::factory()->for($existing)->create(['status' => 'approved']);
        $candidate = LessonSlot::factory()->create([
            'venue_id' => $existing->venue_id, 'starts_at' => $existing->starts_at, 'ends_at' => $existing->ends_at,
        ]);
        $this->assertNotSame($existing->teacher_profile_id, $candidate->teacher_profile_id);
        $this->assertSame([$candidate->id => '会場の同時間帯に別の予約があります。'], app(TeacherBookingAvailability::class)->reasons(collect([$candidate])));
    }

    public function test_unsaved_candidates_cannot_bypass_hours_check(): void
    {
        $slot = LessonSlot::factory()->make(['venue_id' => Venue::factory()->create()->id,
            'starts_at' => '2026-10-12 11:00', 'ends_at' => '2026-10-12 12:00']);
        $this->expectException(ValidationException::class);
        app(TeacherBookingAvailability::class)->assertAvailable($slot, 'slot');
    }

    public function test_trial_booking_rejects_legacy_outside_hours_slot(): void
    {
        $slot = LessonSlot::factory()->forTrials()->create(['starts_at' => '2026-10-12 11:00', 'ends_at' => '2026-10-12 12:00']);
        $this->post(route('trial-lessons.store'), [
            'lesson_slot_id' => $slot->id, 'name' => '確認用', 'name_kana' => 'カクニンヨウ',
            'email' => 'audit@example.invalid', 'phone' => '090-0000-0000',
            'age_group' => 'adult', 'drum_experience' => 'none', 'privacy_accepted' => '1', 'website' => '',
        ])->assertSessionHasErrors('lesson_slot_id');
        $this->assertDatabaseCount('trial_lesson_requests', 0);
        $this->assertSame('11:00', $slot->fresh()->starts_at->format('H:i'));
    }

    public function test_pending_booking_cannot_be_approved_after_contract_is_paused(): void
    {
        $slot = LessonSlot::factory()->create();
        $enrollment = LessonEnrollment::factory()->create(['course_id' => $slot->course_id, 'status' => 'paused']);
        $reservation = ReservationRequest::factory()->for($slot)->create([
            'student_profile_id' => $enrollment->student_profile_id, 'lesson_enrollment_id' => $enrollment->id, 'status' => 'pending',
        ]);
        try {
            app(ReviewReservationRequest::class)->handle($reservation, User::factory()->admin()->create(), ReservationStatus::Approved, null);
            $this->fail('Paused enrollment must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('在籍契約', $exception->getMessage());
        }
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    public function test_regular_draft_is_rechecked_after_future_pause_approval(): void
    {
        $slot = LessonSlot::factory()->make();
        $enrollment = LessonEnrollment::factory()->create([
            'teacher_profile_id' => $slot->teacher_profile_id, 'venue_id' => Venue::factory()->create()->id,
            'weekday' => 6, 'starts_at_time' => '14:00', 'monthly_lesson_limit' => 2,
        ]);
        $batch = app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-11-01'))->sole();
        MembershipStatusRequest::factory()->create(['student_profile_id' => $enrollment->student_profile_id,
            'type' => 'pause', 'status' => 'approved', 'effective_on' => '2026-11-01']);
        $this->assertContains('休会・退会期間のため予約を確定できません。', app(RegularScheduleConflictDetector::class)->detect($batch->occurrences->first()));
    }

    public function test_transfer_cannot_change_a_30_minute_lesson_into_60_minutes(): void
    {
        $source = LessonSlot::factory()->create(['starts_at' => '2026-10-15 12:00', 'ends_at' => '2026-10-15 12:30']);
        $original = ReservationRequest::factory()->for($source)->create(['status' => 'approved']);
        $target = LessonSlot::factory()->create();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('同じ長さ');
        app(CreateTransferRequest::class)->handle($original->studentProfile, $original, $target->id, null, null);
    }

    public function test_student_cannot_transfer_into_another_own_booking_with_different_teacher_and_venue(): void
    {
        $source = LessonSlot::factory()->create(['starts_at' => '2026-10-15 12:00', 'ends_at' => '2026-10-15 13:00']);
        $original = ReservationRequest::factory()->for($source)->create(['status' => 'approved']);
        $target = LessonSlot::factory()->create();
        ReservationRequest::factory()->create(['student_profile_id' => $original->student_profile_id, 'status' => 'approved']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('生徒本人');
        app(CreateTransferRequest::class)->handle($original->studentProfile, $original, $target->id, null, null);
    }
}
