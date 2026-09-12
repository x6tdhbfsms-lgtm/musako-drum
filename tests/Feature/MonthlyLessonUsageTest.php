<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceNoticeType;
use App\Enums\EnrollmentStatus;
use App\Enums\ReservationStatus;
use App\Models\AttendanceNotice;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MonthlyLessonUsageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_summary_counts_completed_confirmed_pending_absent_and_cancelled_lessons(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        [$student, $course, $enrollment] = $this->studentWithEnrollment(4);
        $completed = $this->reservation($student, $course, $enrollment, '2026-09-01 10:00:00', ReservationStatus::Approved);
        $this->reservation($student, $course, $enrollment, '2026-09-20 10:00:00', ReservationStatus::Approved);
        $this->reservation($student, $course, $enrollment, '2026-09-22 10:00:00', ReservationStatus::Pending);
        $absence = $this->reservation($student, $course, $enrollment, '2026-09-05 10:00:00', ReservationStatus::Approved);
        $this->reservation($student, $course, $enrollment, '2026-09-25 10:00:00', ReservationStatus::Cancelled);
        AttendanceNotice::factory()->for($absence)->create(['type' => AttendanceNoticeType::Absence]);

        $summary = app(MonthlyLessonUsageCalculator::class)->calculate($student, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(4, $summary->contracted);
        $this->assertSame(1, $summary->completed);
        $this->assertSame(1, $summary->confirmed);
        $this->assertSame(1, $summary->pending);
        $this->assertSame(1, $summary->absent);
        $this->assertSame(1, $summary->cancelled);
        $this->assertSame(4, $summary->used);
        $this->assertSame(0, $summary->remaining);
        $this->assertSame('completed', $summary->items->firstWhere('reservation.id', $completed->id)['category']);
    }

    public function test_student_cannot_request_more_than_a_two_lesson_monthly_contract(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        [$student, $course, $enrollment] = $this->studentWithEnrollment(2);
        $this->reservation($student, $course, $enrollment, '2026-09-10 10:00:00', ReservationStatus::Approved);
        $this->reservation($student, $course, $enrollment, '2026-09-17 10:00:00', ReservationStatus::Pending);
        $target = $this->slot($course, '2026-09-24 10:00:00');

        $this->actingAs($student->user)
            ->post(route('student.reservations.store', $target))
            ->assertSessionHasErrors(['lesson_slot' => '今月の予約可能回数を使い切っています。']);

        $this->assertDatabaseMissing('reservation_requests', [
            'student_profile_id' => $student->id,
            'lesson_slot_id' => $target->id,
        ]);
    }

    public function test_reservation_within_limit_records_its_lesson_entitlement_month(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        [$student, $course] = $this->studentWithEnrollment(4);
        $target = $this->slot($course, '2026-09-24 10:00:00');

        $this->actingAs($student->user)
            ->post(route('student.reservations.store', $target))
            ->assertRedirectToRoute('student.reservations.index');

        $this->assertDatabaseHas('reservation_requests', [
            'student_profile_id' => $student->id,
            'lesson_slot_id' => $target->id,
            'lesson_entitlement_month' => '2026-09-01 00:00:00',
            'status' => ReservationStatus::Pending->value,
        ]);
    }

    public function test_approved_cross_month_transfer_uses_the_original_month_once(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $teacher = TeacherProfile::factory()->create();
        [$student, $course, $enrollment] = $this->studentWithEnrollment(2, $teacher);
        $original = $this->reservation($student, $course, $enrollment, '2026-09-20 10:00:00', ReservationStatus::Approved, $teacher);
        $target = $this->slot($course, '2026-10-05 10:00:00', $teacher);
        $transfer = TransferRequest::factory()->create([
            'student_profile_id' => $student->id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $target->id,
        ]);

        $this->actingAs($teacher->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => ApplicationStatus::Approved->value])
            ->assertRedirectToRoute('staff.transfer-requests.show', $transfer);

        $september = app(MonthlyLessonUsageCalculator::class)->calculate($student, CarbonImmutable::parse('2026-09-01'));
        $october = app(MonthlyLessonUsageCalculator::class)->calculate($student, CarbonImmutable::parse('2026-10-01'));
        $resulting = $transfer->fresh()->resultingReservationRequest;

        $this->assertSame('2026-09-01', $resulting->lesson_entitlement_month->toDateString());
        $this->assertSame(1, $september->used);
        $this->assertSame(1, $september->transferScheduled);
        $this->assertSame(0, $october->used);
        $this->assertSame(1, $september->items->count());
        $this->assertTrue($september->items->first()['is_transfer']);
    }

    public function test_contract_change_effective_mid_month_uses_the_latest_monthly_limit(): void
    {
        $student = StudentProfile::factory()->create();
        $course = Course::factory()->create();
        $old = LessonEnrollment::factory()->for($student)->for($course)->create([
            'monthly_lesson_limit' => 2,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-09-14',
        ]);
        LessonEnrollment::factory()->for($student)->for($course)->create([
            'monthly_lesson_limit' => 4,
            'starts_on' => '2026-09-15',
            'supersedes_lesson_enrollment_id' => $old->id,
        ]);

        $summary = app(MonthlyLessonUsageCalculator::class)->calculate($student, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(4, $summary->contracted);
        $this->assertSame(4, $summary->remaining);
    }

    public function test_student_dashboard_summary_excludes_another_students_lessons(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        [$student, $course, $enrollment] = $this->studentWithEnrollment(4);
        $this->reservation($student, $course, $enrollment, '2026-09-10 10:00:00', ReservationStatus::Approved);
        [$otherStudent, $otherCourse, $otherEnrollment] = $this->studentWithEnrollment(4);
        $this->reservation($otherStudent, $otherCourse, $otherEnrollment, '2026-09-11 10:00:00', ReservationStatus::Approved);

        $this->actingAs($student->user)
            ->get(route('student.dashboard', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('1 / 4', false)
            ->assertSee('残り 3回');
    }

    public function test_staff_override_is_required_and_audited_for_an_over_limit_approval(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $teacher = TeacherProfile::factory()->create();
        [$student, $course, $enrollment] = $this->studentWithEnrollment(2, $teacher);
        $this->reservation($student, $course, $enrollment, '2026-09-10 10:00:00', ReservationStatus::Approved, $teacher);
        $this->reservation($student, $course, $enrollment, '2026-09-17 10:00:00', ReservationStatus::Approved, $teacher);
        $pending = $this->reservation($student, $course, $enrollment, '2026-09-24 10:00:00', ReservationStatus::Pending, $teacher);

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $pending), ['decision' => ReservationStatus::Approved->value])
            ->assertSessionHasErrors('reservation');

        $this->assertSame(ReservationStatus::Pending, $pending->fresh()->status);

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $pending), [
                'decision' => ReservationStatus::Approved->value,
                'override_monthly_limit' => '1',
                'monthly_limit_override_reason' => '発表会前の追加レッスン',
            ])
            ->assertRedirectToRoute('staff.reservations.index');

        $this->assertDatabaseHas('reservation_requests', [
            'id' => $pending->id,
            'status' => ReservationStatus::Approved->value,
            'monthly_limit_overridden_by_user_id' => $teacher->user_id,
            'monthly_limit_override_reason' => '発表会前の追加レッスン',
        ]);
        $this->assertNotNull($pending->fresh()->monthly_limit_overridden_at);
    }

    /** @return array{StudentProfile, Course, LessonEnrollment} */
    private function studentWithEnrollment(int $monthlyLimit, ?TeacherProfile $teacher = null): array
    {
        $student = StudentProfile::factory()->create();
        $course = Course::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->for($course)->create([
            'teacher_profile_id' => $teacher?->id,
            'monthly_lesson_limit' => $monthlyLimit,
            'status' => EnrollmentStatus::Active,
            'starts_on' => '2026-01-01',
        ]);

        return [$student, $course, $enrollment];
    }

    private function reservation(
        StudentProfile $student,
        Course $course,
        LessonEnrollment $enrollment,
        string $startsAt,
        ReservationStatus $status,
        ?TeacherProfile $teacher = null,
    ): ReservationRequest {
        $slot = $this->slot($course, $startsAt, $teacher);

        return ReservationRequest::factory()->for($student)->for($slot)->create([
            'lesson_enrollment_id' => $enrollment->id,
            'lesson_entitlement_month' => CarbonImmutable::parse($startsAt)->startOfMonth()->toDateString(),
            'status' => $status,
        ]);
    }

    private function slot(Course $course, string $startsAt, ?TeacherProfile $teacher = null): LessonSlot
    {
        $starts = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return LessonSlot::factory()
            ->for($teacher ?? TeacherProfile::factory()->create())
            ->for($course)
            ->create([
                'starts_at' => $starts,
                'ends_at' => $starts->addHour(),
                'capacity' => 5,
            ]);
    }
}
