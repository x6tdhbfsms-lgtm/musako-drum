<?php

namespace Tests\Feature;

use App\Actions\ConfirmRegularScheduleOccurrences;
use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\MembershipRequestType;
use App\Enums\RegularScheduleOccurrenceStatus;
use App\Enums\ReservationStatus;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\RegularScheduleAudit;
use App\Models\RegularScheduleBatch;
use App\Models\RegularScheduleNotificationDelivery;
use App\Models\RegularScheduleOccurrence;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\RegularScheduleConfirmedNotification;
use App\Services\RegularScheduleGenerator;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegularScheduleManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_regular_contracts_generate_configured_week_patterns_for_monthly_counts_one_to_four(): void
    {
        $teacher = TeacherProfile::factory()->create();
        foreach ([[1, [2]], [2, [1, 3]], [3, [1, 2, 4]], [4, [1, 2, 3, 4]]] as [$count, $weeks]) {
            $this->regularEnrollment($teacher, $count, $weeks, ['starts_at_time' => sprintf('%02d:00', 9 + $count)]);
        }

        app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'));

        foreach ([[1, [2]], [2, [1, 3]], [3, [1, 2, 4]], [4, [1, 2, 3, 4]]] as [$count, $weeks]) {
            $batch = RegularScheduleBatch::query()->where('expected_count', $count)->firstOrFail();
            $this->assertSame($weeks, $batch->occurrences()->orderBy('week_number')->pluck('week_number')->all());
            $this->assertSame($count, $batch->generated_count);
        }
    }

    public function test_flex_contract_is_not_generated_and_fifth_week_requires_explicit_selection(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $flex = $this->regularEnrollment($teacher, 2, [1, 3], ['lesson_type' => LessonType::Flex]);
        $regular = $this->regularEnrollment($teacher, 3, [1, 3, 5], ['starts_at_time' => '16:00']);

        app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'));

        $this->assertDatabaseMissing('regular_schedule_batches', ['lesson_enrollment_id' => $flex->id]);
        $this->assertSame([3, 17, 31], RegularScheduleOccurrence::query()->where('lesson_enrollment_id', $regular->id)->orderBy('starts_at')->get()->map(fn ($item) => $item->starts_at->day)->all());
    }

    public function test_generation_is_idempotent_and_preserves_manual_edits(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $enrollment = $this->regularEnrollment($teacher, 2, [1, 3]);
        $generator = app(RegularScheduleGenerator::class);
        $generator->generateMonth(CarbonImmutable::parse('2026-10-01'));
        $occurrence = RegularScheduleOccurrence::query()->orderBy('id')->firstOrFail();
        $occurrence->update(['starts_at' => '2026-10-04 15:00:00', 'ends_at' => '2026-10-04 16:00:00', 'manually_adjusted' => true]);

        $generator->generateMonth(CarbonImmutable::parse('2026-10-01'));

        $this->assertDatabaseCount('regular_schedule_batches', 1);
        $this->assertDatabaseCount('regular_schedule_occurrences', 2);
        $this->assertSame('2026-10-04 15:00', $occurrence->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame(2, RegularScheduleAudit::query()->where('action', 'generated')->count());
        $this->assertSame(1, RegularScheduleAudit::query()->where('action', 'regenerated')->count());
        $this->assertSame($enrollment->id, $occurrence->lesson_enrollment_id);
    }

    public function test_confirmation_creates_an_approved_reservation_with_entitlement_and_monthly_notification(): void
    {
        Notification::fake();
        $teacher = TeacherProfile::factory()->create();
        $enrollment = $this->regularEnrollment($teacher, 2, [1, 3]);
        $batch = app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'))->first();
        $admin = User::factory()->admin()->create();

        app(ConfirmRegularScheduleOccurrences::class)->handle($batch->occurrences->modelKeys(), $admin);

        $this->assertDatabaseCount('reservation_requests', 2);
        $reservation = ReservationRequest::query()->firstOrFail();
        $this->assertSame(ReservationStatus::Approved, $reservation->status);
        $this->assertSame('2026-10-01', $reservation->lesson_entitlement_month->toDateString());
        $this->assertNotNull($reservation->regular_schedule_occurrence_id);
        $this->assertSame($admin->id, $reservation->reviewed_by_user_id);
        $summary = app(MonthlyLessonUsageCalculator::class)->calculate($enrollment->studentProfile, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame(2, $summary->used);
        $this->assertDatabaseCount('regular_schedule_notification_deliveries', 1);
        Notification::assertSentTo($enrollment->studentProfile->user, RegularScheduleConfirmedNotification::class, 1);
    }

    public function test_notification_is_deduplicated_for_the_same_confirmed_schedule_signature(): void
    {
        Notification::fake();
        $teacher = TeacherProfile::factory()->create();
        $enrollment = $this->regularEnrollment($teacher, 1, [1]);
        $batch = app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'))->first();
        $admin = User::factory()->admin()->create();
        $action = app(ConfirmRegularScheduleOccurrences::class);

        $action->handle($batch->occurrences->modelKeys(), $admin);
        $action->handle($batch->occurrences->modelKeys(), $admin);

        $this->assertSame(1, RegularScheduleNotificationDelivery::query()->count());
        Notification::assertSentTo($enrollment->studentProfile->user, RegularScheduleConfirmedNotification::class, 1);
    }

    public function test_teacher_and_student_time_conflicts_are_detected_and_cannot_be_confirmed(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $first = $this->regularEnrollment($teacher, 1, [1]);
        $second = $this->regularEnrollment($teacher, 1, [1]);
        $generator = app(RegularScheduleGenerator::class);

        $generator->generateMonth(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(2, RegularScheduleOccurrence::query()->where('status', RegularScheduleOccurrenceStatus::Conflict)->count());
        $this->actingAs($teacher->user)
            ->post(route('staff.regular-schedules.confirm'), ['occurrence_ids' => [$first->regularScheduleBatches->first()->occurrences->first()->id]])
            ->assertSessionHasErrors('occurrences');
        $this->assertDatabaseCount('reservation_requests', 0);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_existing_full_slot_is_reported_as_a_conflict(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $enrollment = $this->regularEnrollment($teacher, 1, [1]);
        $starts = CarbonImmutable::parse('2026-10-03 14:00');
        $slot = LessonSlot::factory()->for($teacher)->for($enrollment->course)->create([
            'venue_id' => $enrollment->venue_id, 'starts_at' => $starts, 'ends_at' => $starts->addHour(), 'capacity' => 1,
        ]);
        ReservationRequest::factory()->for($slot)->create(['status' => ReservationStatus::Approved]);

        app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'));

        $occurrence = RegularScheduleOccurrence::query()->firstOrFail();
        $this->assertSame(RegularScheduleOccurrenceStatus::Conflict, $occurrence->status);
        $this->assertStringContainsString('定員', implode('', $occurrence->conflict_reasons));
    }

    public function test_start_date_and_approved_pause_create_a_shortage_warning_without_filling_other_weeks(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $enrollment = $this->regularEnrollment($teacher, 2, [1, 3], ['starts_on' => '2026-10-10']);
        MembershipStatusRequest::factory()->for($enrollment->studentProfile)->create([
            'type' => MembershipRequestType::Pause,
            'effective_on' => '2026-10-18',
            'status' => ApplicationStatus::Approved,
        ]);

        $batch = app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'))->first();

        $this->assertSame(1, $batch->generated_count);
        $this->assertNotNull($batch->warning);
        $this->assertSame(17, $batch->occurrences->first()->starts_at->day);
    }

    public function test_mid_month_contract_change_uses_old_and_new_effective_schedules_without_exceeding_new_limit(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $old = $this->regularEnrollment($teacher, 2, [1, 3], ['ends_on' => '2026-10-14']);
        $replacement = LessonEnrollment::factory()->for($old->studentProfile)->for($old->course)->create([
            'lesson_type' => LessonType::Regular,
            'status' => EnrollmentStatus::Active,
            'teacher_profile_id' => $teacher->id,
            'venue_id' => $old->venue_id,
            'weekday' => 6,
            'starts_at_time' => '16:00',
            'lesson_minutes' => 60,
            'monthly_lesson_limit' => 3,
            'regular_week_numbers' => [2, 3, 4],
            'starts_on' => '2026-10-15',
            'supersedes_lesson_enrollment_id' => $old->id,
        ]);

        $batch = app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'))->first();

        $this->assertSame($replacement->id, $batch->lesson_enrollment_id);
        $this->assertSame(3, $batch->expected_count);
        $this->assertSame(
            ['2026-10-03 14:00', '2026-10-17 16:00', '2026-10-24 16:00'],
            $batch->occurrences->sortBy('starts_at')->map(fn ($item) => $item->starts_at->format('Y-m-d H:i'))->values()->all(),
        );
    }

    public function test_teacher_scope_and_student_authorization_are_enforced(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $this->regularEnrollment($teacher, 1, [1]);
        $other = $this->regularEnrollment($otherTeacher, 1, [2]);

        $this->actingAs($teacher->user)->post(route('staff.regular-schedules.generate'), ['month' => '2026-10'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('regular_schedule_batches', 1);
        $this->assertDatabaseMissing('regular_schedule_batches', ['lesson_enrollment_id' => $other->id]);
        $this->actingAs($other->studentProfile->user)->get(route('staff.regular-schedules.index'))->assertForbidden();
    }

    public function test_draft_can_be_edited_but_confirmed_occurrence_cannot_be_directly_edited(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $enrollment = $this->regularEnrollment($teacher, 1, [1]);
        $occurrence = app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'))->first()->occurrences->first();

        $this->actingAs($teacher->user)->patch(route('staff.regular-schedules.occurrences.update', $occurrence), [
            'starts_at' => '2026-10-04T15:00', 'teacher_profile_id' => $teacher->id, 'venue_id' => $enrollment->venue_id,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($occurrence->fresh()->manually_adjusted);

        app(ConfirmRegularScheduleOccurrences::class)->handle([$occurrence->id], $teacher->user);
        $this->actingAs($teacher->user)->patch(route('staff.regular-schedules.occurrences.update', $occurrence), [
            'starts_at' => '2026-10-05T15:00', 'teacher_profile_id' => $teacher->id, 'venue_id' => $enrollment->venue_id,
        ])->assertSessionHasErrors('occurrence');
    }

    public function test_scheduler_command_obeys_setting_and_can_be_forced(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $this->regularEnrollment($teacher, 1, [1]);
        $this->travelTo('2026-09-19 10:00:00');

        $this->artisan('regular-schedules:generate-next-month')->assertSuccessful();
        $this->assertDatabaseCount('regular_schedule_batches', 0);
        $this->artisan('regular-schedules:generate-next-month --force')->assertSuccessful();
        $this->assertDatabaseCount('regular_schedule_batches', 1);
    }

    public function test_draft_can_be_skipped_and_regeneration_does_not_restore_it(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $this->regularEnrollment($teacher, 1, [1]);
        $generator = app(RegularScheduleGenerator::class);
        $occurrence = $generator->generateMonth(CarbonImmutable::parse('2026-10-01'))->first()->occurrences->first();

        $this->actingAs($teacher->user)->patch(route('staff.regular-schedules.occurrences.status', $occurrence), [
            'status' => RegularScheduleOccurrenceStatus::Skipped->value,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $generator->generateMonth(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(RegularScheduleOccurrenceStatus::Skipped, $occurrence->fresh()->status);
        $this->assertDatabaseHas('regular_schedule_audits', ['regular_schedule_occurrence_id' => $occurrence->id, 'action' => 'skipped']);
    }

    public function test_confirmed_occurrence_cancellation_keeps_reservation_history_without_usage(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $enrollment = $this->regularEnrollment($teacher, 1, [1]);
        $occurrence = app(RegularScheduleGenerator::class)->generateMonth(CarbonImmutable::parse('2026-10-01'))->first()->occurrences->first();
        Notification::fake();
        app(ConfirmRegularScheduleOccurrences::class)->handle([$occurrence->id], $teacher->user);

        $this->actingAs($teacher->user)->patch(route('staff.regular-schedules.occurrences.status', $occurrence), [
            'status' => RegularScheduleOccurrenceStatus::Cancelled->value,
            'reason' => '教室休講日',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation = $occurrence->fresh()->reservationRequest;
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertSame('教室休講日', $reservation->cancellation_reason);
        $this->assertSame(0, app(MonthlyLessonUsageCalculator::class)->calculate($enrollment->studentProfile, CarbonImmutable::parse('2026-10-01'))->used);
        $this->assertDatabaseHas('regular_schedule_audits', ['regular_schedule_occurrence_id' => $occurrence->id, 'action' => 'cancelled']);
    }

    private function regularEnrollment(TeacherProfile $teacher, int $count, array $weeks, array $overrides = []): LessonEnrollment
    {
        $student = StudentProfile::factory()->create();
        $course = Course::factory()->create();
        $venue = Venue::factory()->create();

        return LessonEnrollment::factory()->for($student)->for($course)->create(array_merge([
            'lesson_type' => LessonType::Regular,
            'status' => EnrollmentStatus::Active,
            'teacher_profile_id' => $teacher->id,
            'venue_id' => $venue->id,
            'weekday' => 6,
            'starts_at_time' => '14:00',
            'lesson_minutes' => 60,
            'monthly_lesson_limit' => $count,
            'regular_week_numbers' => $weeks,
            'starts_on' => '2026-01-01',
        ], $overrides));
    }
}
