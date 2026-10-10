<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\LessonSlotAudience;
use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\TrialLessonRequest;
use App\Models\Venue;
use App\Services\RegularScheduleGenerator;
use App\Services\TeacherBookingAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeacherTravelBookingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function travelTimes(): array
    {
        return [
            'studio must use a full hour' => ['13:30', '14:00', false, false],
            'studio before with exactly thirty minutes' => ['10:30', '11:30', false, true],
            'studio before without travel time' => ['11:30', '12:30', false, false],
            'studio after without travel time' => ['13:00', '14:00', false, false],
            'studio after rounded up to half hour' => ['13:30', '14:30', false, true],
            'studio cannot start at quarter past' => ['13:15', '14:15', false, false],
            'electronic thirty minutes immediately before' => ['11:45', '12:15', true, true],
            'electronic thirty minutes immediately after' => ['13:00', '13:30', true, true],
            'electronic forty five minutes immediately after' => ['13:00', '13:45', true, true],
            'electronic overlapping is still blocked' => ['12:30', '13:15', true, false],
        ];
    }

    #[DataProvider('travelTimes')]
    public function test_available_slots_account_for_the_actual_lesson_end_and_venue(string $start, string $end, bool $sameVenue, bool $allowed): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = LessonSlot::factory()->create([
            'teacher_profile_id' => $existing->lessonSlot->teacher_profile_id,
            'venue_id' => $sameVenue ? $existing->lessonSlot->venue_id : $this->studio()->id,
            'starts_at' => '2026-09-20 '.$start,
            'ends_at' => '2026-09-20 '.$end,
        ]);

        $available = app(TeacherBookingAvailability::class)->availableSlots(collect([$slot]));

        $this->assertSame($allowed ? [$slot->id] : [], $available->pluck('id')->all());
    }

    public function test_different_teachers_are_not_blocked_by_each_others_travel(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $this->electronicBooking();
        $slot = LessonSlot::factory()->for($this->studio())->create([
            'starts_at' => '2026-09-20 12:00',
            'ends_at' => '2026-09-20 13:00',
        ]);

        $this->assertSame([], app(TeacherBookingAvailability::class)->reasons(collect([$slot])));
    }

    public function test_cancellation_automatically_releases_the_travel_block(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $existing->update(['status' => ReservationStatus::Cancelled]);

        $this->assertSame([], app(TeacherBookingAvailability::class)->reasons(collect([$slot])));
    }

    public function test_pending_reservations_hold_the_travel_time(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $existing->update(['status' => ReservationStatus::Pending]);
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);

        $this->assertSame([$slot->id => TeacherBookingAvailability::TRAVEL_MESSAGE], app(TeacherBookingAvailability::class)->reasons(collect([$slot])));
    }

    public function test_trial_lessons_also_hold_the_travel_time(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $trial = TrialLessonRequest::factory()->approved()->create([
            'lesson_slot_id' => LessonSlot::factory()->forTrials()->create([
                'starts_at' => '2026-09-20 12:15', 'ends_at' => '2026-09-20 13:00',
            ])->id,
        ]);
        $slot = $this->studioSlot($trial->lessonSlot->teacherProfile);

        $this->assertSame([$slot->id => TeacherBookingAvailability::TRAVEL_MESSAGE], app(TeacherBookingAvailability::class)->reasons(collect([$slot])));
    }

    public function test_normal_booking_cannot_bypass_the_travel_block_by_posting_a_slot_id(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['course_id' => $slot->course_id]);

        $this->actingAs($student->user)->post(route('student.reservations.store', $slot))
            ->assertSessionHasErrors(['lesson_slot' => TeacherBookingAvailability::TRAVEL_MESSAGE]);

        $this->assertDatabaseMissing('reservation_requests', ['lesson_slot_id' => $slot->id]);
    }

    public function test_staff_approval_rechecks_travel_for_an_older_pending_request(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $pending = ReservationRequest::factory()->for($slot)->create();

        $this->actingAs($slot->teacherProfile->user)->patch(route('staff.reservations.update', $pending), ['decision' => 'approved'])
            ->assertSessionHasErrors(['reservation' => TeacherBookingAvailability::TRAVEL_MESSAGE]);

        $this->assertSame(ReservationStatus::Pending, $pending->fresh()->status);
    }

    public function test_transfer_booking_cannot_bypass_the_travel_block(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $original = $this->originalReservation();

        $this->actingAs($original->studentProfile->user)
            ->post(route('student.transfer-requests.store', $original), ['requested_lesson_slot_id' => $slot->id])
            ->assertSessionHasErrors(['requested_lesson_slot_id' => TeacherBookingAvailability::TRAVEL_MESSAGE]);

        $this->assertDatabaseCount('transfer_requests', 0);
    }

    public function test_transfer_screen_only_offers_slots_with_enough_travel_time(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $blocked = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $allowed = LessonSlot::factory()->create([
            'teacher_profile_id' => $blocked->teacher_profile_id, 'venue_id' => $blocked->venue_id,
            'starts_at' => '2026-09-20 13:30', 'ends_at' => '2026-09-20 14:30',
        ]);
        $original = $this->originalReservation();
        $original->lessonSlot->update(['teacher_profile_id' => $blocked->teacher_profile_id, 'venue_id' => $blocked->venue_id]);

        $this->actingAs($original->studentProfile->user)
            ->get(route('student.transfer-requests.create', $original))
            ->assertOk()
            ->assertViewHas('availableSlots', fn ($slots) => $slots->contains(fn ($slot) => $slot->starts_at->equalTo($allowed->starts_at)) && ! $slots->contains(fn ($slot) => $slot->starts_at->equalTo($blocked->starts_at)));
    }

    public function test_transfer_approval_rechecks_travel_without_cancelling_the_original(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $original = $this->originalReservation();
        $transfer = TransferRequest::factory()->create([
            'student_profile_id' => $original->student_profile_id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $slot->id,
        ]);

        $this->actingAs($original->lessonSlot->teacherProfile->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])
            ->assertSessionHasErrors(['transfer_request' => TeacherBookingAvailability::TRAVEL_MESSAGE]);

        $this->assertSame(ReservationStatus::Approved, $original->fresh()->status);
        $this->assertSame(ApplicationStatus::Pending, $transfer->fresh()->status);
        $this->assertDatabaseMissing('reservation_requests', ['lesson_slot_id' => $slot->id]);
    }

    public function test_an_empty_overlapping_slot_does_not_reserve_the_teacher(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $empty = LessonSlot::factory()->create(['starts_at' => '2026-09-20 12:15', 'ends_at' => '2026-09-20 13:00']);
        $slot = $this->studioSlot($empty->teacherProfile);

        $this->assertSame([], app(TeacherBookingAvailability::class)->reasons(collect([$slot])));
    }

    public function test_a_thirty_minute_electronic_lesson_allows_studio_at_thirteen(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $existing->lessonSlot->update(['starts_at' => '2026-09-20 12:00', 'ends_at' => '2026-09-20 12:30']);
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);

        $this->assertSame([], app(TeacherBookingAvailability::class)->reasons(collect([$slot])));
    }

    public function test_transfer_can_replace_its_own_original_slot_but_not_other_students_bookings(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $original = $this->electronicBooking();
        $slot = $this->studioSlot($original->lessonSlot->teacherProfile);
        $availability = app(TeacherBookingAvailability::class);

        $this->assertSame([], $availability->reasons(collect([$slot]), $original->id));

        ReservationRequest::factory()->for($original->lessonSlot)->create(['status' => ReservationStatus::Approved]);

        $this->assertSame([$slot->id => TeacherBookingAvailability::TRAVEL_MESSAGE], $availability->reasons(collect([$slot]), $original->id));
    }

    public function test_pending_transfer_holds_are_released_after_rejection(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $target = LessonSlot::factory()->create(['starts_at' => '2026-09-20 12:15', 'ends_at' => '2026-09-20 13:00']);
        $original = $this->originalReservation();
        $transfer = TransferRequest::factory()->create([
            'student_profile_id' => $original->student_profile_id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $target->id,
        ]);
        $slot = $this->studioSlot($target->teacherProfile);
        $availability = app(TeacherBookingAvailability::class);

        $this->assertSame([$slot->id => TeacherBookingAvailability::TRAVEL_MESSAGE], $availability->reasons(collect([$slot])));
        $transfer->update(['status' => ApplicationStatus::Rejected]);
        $this->assertSame([], $availability->reasons(collect([$slot])));
    }

    public function test_trial_submission_cannot_bypass_the_travel_block(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $slot->update(['booking_audience' => LessonSlotAudience::Trial]);

        $this->post(route('trial-lessons.store'), [
            'lesson_slot_id' => $slot->id, 'name' => '体験 太郎', 'name_kana' => 'タイケン タロウ',
            'email' => 'travel-test@example.test', 'phone' => '090-1234-5678',
            'age_group' => 'adult', 'drum_experience' => 'none', 'privacy_accepted' => '1', 'website' => '',
        ])->assertSessionHasErrors(['lesson_slot_id' => TeacherBookingAvailability::TRAVEL_MESSAGE]);

        $this->assertDatabaseCount('trial_lesson_requests', 0);
    }

    public function test_trial_approval_rechecks_the_travel_block(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $slot = $this->studioSlot($existing->lessonSlot->teacherProfile);
        $trial = TrialLessonRequest::factory()->for($slot)->create();

        $this->actingAs($slot->teacherProfile->user)->patch(route('staff.trial-lessons.update', $trial), ['status' => 'approved'])
            ->assertSessionHasErrors(['status' => TeacherBookingAvailability::TRAVEL_MESSAGE]);

        $this->assertSame(TrialLessonStatus::Pending, $trial->fresh()->status);
    }

    public function test_regular_schedule_confirmation_respects_travel_time(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $existing = $this->electronicBooking();
        $teacher = $existing->lessonSlot->teacherProfile;
        LessonEnrollment::factory()->create([
            'teacher_profile_id' => $teacher->id, 'venue_id' => $this->studio()->id,
            'weekday' => 0, 'starts_at_time' => '13:00', 'lesson_minutes' => 60,
            'monthly_lesson_limit' => 1, 'regular_week_numbers' => [3],
            'starts_on' => '2026-09-01',
        ]);
        $batch = app(RegularScheduleGenerator::class)
            ->generateMonth(CarbonImmutable::parse('2026-09-01'))->sole();

        $this->actingAs($teacher->user)->post(route('staff.regular-schedules.confirm'), [
            'occurrence_ids' => $batch->occurrences->modelKeys(),
        ])->assertSessionHasErrors('occurrences');

        $this->assertDatabaseCount('reservation_requests', 1);
        $this->assertContains(TeacherBookingAvailability::TRAVEL_MESSAGE, $batch->occurrences->sole()->conflict_reasons);
    }

    public function test_transfer_does_not_offer_or_accept_trial_only_slots(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $original = $this->originalReservation();
        $target = LessonSlot::factory()->forTrials()->create([
            'starts_at' => '2026-09-20 14:00', 'ends_at' => '2026-09-20 15:00',
        ]);

        $this->actingAs($original->studentProfile->user)->post(route('student.transfer-requests.store', $original), [
            'requested_lesson_slot_id' => $target->id,
        ])->assertSessionHasErrors('requested_lesson_slot_id');

        $this->assertDatabaseCount('transfer_requests', 0);
    }

    private function electronicBooking(): ReservationRequest
    {
        $slot = LessonSlot::factory()->create([
            'venue_id' => Venue::factory()->create(['name' => '電子ドラムコース'])->id,
            'starts_at' => '2026-09-20 12:15', 'ends_at' => '2026-09-20 13:00',
        ]);

        return ReservationRequest::factory()->for($slot)->create(['status' => ReservationStatus::Approved]);
    }

    private function studio(): Venue
    {
        return Venue::factory()->create(['code' => 'STUDIO34', 'name' => 'studio34']);
    }

    private function studioSlot(TeacherProfile $teacher): LessonSlot
    {
        return LessonSlot::factory()->for($teacher)->for($this->studio())->create([
            'starts_at' => '2026-09-20 13:00', 'ends_at' => '2026-09-20 14:00',
        ]);
    }

    private function originalReservation(): ReservationRequest
    {
        return ReservationRequest::factory()->create([
            'lesson_slot_id' => LessonSlot::factory()->create([
                'starts_at' => '2026-09-25 14:00', 'ends_at' => '2026-09-25 15:00',
            ])->id,
            'status' => ReservationStatus::Approved,
            'studio_fee_amount' => 1000, 'studio_fee_priced_on' => '2026-09-25',
        ]);
    }
}
