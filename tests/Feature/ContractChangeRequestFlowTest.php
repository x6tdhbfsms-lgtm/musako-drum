<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ContractChangeType;
use App\Enums\EnrollmentStatus;
use App\Enums\ReservationStatus;
use App\Models\ContractChangeRequest;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ContractChangeRequestFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_can_submit_a_schedule_change_without_changing_the_current_contract(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        [$student, $enrollment] = $this->studentWithEnrollment();

        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::Schedule->value,
            'lesson_enrollment_id' => $enrollment->id,
            'weekday' => 3,
            'starts_at_time' => '19:00',
            'effective_on' => '2026-10-01',
            'student_note' => '水曜夜を希望',
        ])->assertRedirectToRoute('student.contract-change-requests.index', ['type' => 'schedule']);

        $request = ContractChangeRequest::query()->firstOrFail();
        $this->assertSame(ApplicationStatus::Pending, $request->status);
        $this->assertSame(3, $request->after_values['weekday']);
        $this->assertSame(2, $enrollment->fresh()->weekday);
        $this->assertNull($enrollment->fresh()->ends_on);
    }

    public function test_student_cannot_submit_a_duplicate_pending_contract_change(): void
    {
        [$student, $enrollment] = $this->studentWithEnrollment();
        ContractChangeRequest::create($this->requestAttributes($student, $enrollment));

        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::MonthlyLessons->value,
            'lesson_enrollment_id' => $enrollment->id,
            'monthly_lesson_limit' => 2,
            'effective_on' => today()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('contract_change_requests', 1);
    }

    public function test_student_cannot_change_another_students_contract(): void
    {
        [$student] = $this->studentWithEnrollment();
        [, $otherEnrollment] = $this->studentWithEnrollment();

        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::MonthlyLessons->value,
            'lesson_enrollment_id' => $otherEnrollment->id,
            'monthly_lesson_limit' => 2,
            'effective_on' => today()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('lesson_enrollment_id');

        $this->assertDatabaseCount('contract_change_requests', 0);
    }

    public function test_unknown_or_inactive_course_and_venue_are_rejected(): void
    {
        [$student, $enrollment] = $this->studentWithEnrollment();
        $inactiveCourse = Course::factory()->create(['is_active' => false]);
        $inactiveVenue = Venue::factory()->create(['is_active' => false]);

        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::CourseChange->value,
            'lesson_enrollment_id' => $enrollment->id,
            'course_id' => $inactiveCourse->id,
            'effective_on' => today()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('course_id');
        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::VenueChange->value,
            'lesson_enrollment_id' => $enrollment->id,
            'venue_id' => $inactiveVenue->id,
            'effective_on' => today()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('venue_id');
        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::CourseChange->value,
            'lesson_enrollment_id' => $enrollment->id,
            'course_id' => 999999,
            'effective_on' => today()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('course_id');

        $this->assertDatabaseCount('contract_change_requests', 0);
    }

    public function test_approved_course_and_venue_changes_create_successive_contract_history(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        [$student, $enrollment] = $this->studentWithEnrollment();
        $newCourse = Course::factory()->create(['name' => 'グループレッスン']);
        $newVenue = Venue::factory()->create(['name' => '武蔵小金井第二会場']);
        $admin = User::factory()->admin()->create();
        $courseRequest = ContractChangeRequest::create([
            ...$this->requestAttributes($student, $enrollment),
            'type' => ContractChangeType::CourseChange,
            'effective_on' => '2026-10-01',
            'after_values' => ['course_id' => $newCourse->id],
        ]);
        $this->actingAs($admin)->patch(route('staff.contract-change-requests.update', $courseRequest), [
            'decision' => ApplicationStatus::Approved->value,
        ]);
        $courseVersion = LessonEnrollment::query()->where('supersedes_lesson_enrollment_id', $enrollment->id)->firstOrFail();
        $venueRequest = ContractChangeRequest::create([
            ...$this->requestAttributes($student, $courseVersion),
            'type' => ContractChangeType::VenueChange,
            'effective_on' => '2026-11-01',
            'after_values' => ['venue_id' => $newVenue->id],
        ]);

        $this->actingAs($admin)->patch(route('staff.contract-change-requests.update', $venueRequest), [
            'decision' => ApplicationStatus::Approved->value,
        ]);

        $venueVersion = LessonEnrollment::query()->where('supersedes_lesson_enrollment_id', $courseVersion->id)->firstOrFail();
        $this->assertSame($newCourse->id, $courseVersion->course_id);
        $this->assertSame('2026-10-31', $courseVersion->fresh()->ends_on->toDateString());
        $this->assertSame($newCourse->id, $venueVersion->course_id);
        $this->assertSame($newVenue->id, $venueVersion->venue_id);
        $this->assertDatabaseCount('lesson_enrollments', 3);
    }

    public function test_approved_course_addition_preserves_the_existing_contract(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        [$student, $enrollment] = $this->studentWithEnrollment();
        $additionalCourse = Course::factory()->create(['default_monthly_lessons' => 2, 'default_lesson_minutes' => 45]);
        $request = ContractChangeRequest::create([
            'student_profile_id' => $student->id,
            'type' => ContractChangeType::CourseAdd,
            'effective_on' => '2026-10-01',
            'after_values' => ['course_id' => $additionalCourse->id],
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('staff.contract-change-requests.update', $request), [
            'decision' => ApplicationStatus::Approved->value,
        ]);

        $addition = $student->enrollments()->where('course_id', $additionalCourse->id)->firstOrFail();
        $this->assertNull($enrollment->fresh()->ends_on);
        $this->assertSame('2026-10-01', $addition->starts_on->toDateString());
        $this->assertSame(2, $addition->monthly_lesson_limit);
        $this->assertSame(45, $addition->lesson_minutes);
        $this->assertDatabaseCount('lesson_enrollments', 2);
    }

    public function test_approval_creates_a_future_contract_version_and_keeps_existing_reservation_unchanged(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        [$student, $enrollment, $teacher, $venue] = $this->studentWithEnrollment();
        $slot = LessonSlot::factory()->for($teacher)->for($venue)->for($enrollment->course)->create([
            'starts_at' => '2026-10-10 18:00:00',
            'ends_at' => '2026-10-10 19:00:00',
        ]);
        $reservation = ReservationRequest::factory()->for($student)->for($slot)->for($enrollment, 'lessonEnrollment')->create([
            'status' => ReservationStatus::Approved,
        ]);
        $request = ContractChangeRequest::create([
            ...$this->requestAttributes($student, $enrollment),
            'effective_on' => '2026-10-01',
            'after_values' => ['monthly_lesson_limit' => 2],
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('staff.contract-change-requests.update', $request), [
            'decision' => ApplicationStatus::Approved->value,
        ])->assertRedirectToRoute('staff.contract-change-requests.show', $request);

        $replacement = LessonEnrollment::query()->where('supersedes_lesson_enrollment_id', $enrollment->id)->firstOrFail();
        $this->assertSame('2026-09-30', $enrollment->fresh()->ends_on->toDateString());
        $this->assertSame('2026-10-01', $replacement->starts_on->toDateString());
        $this->assertSame(2, $replacement->monthly_lesson_limit);
        $this->assertSame($enrollment->id, $reservation->fresh()->lesson_enrollment_id);
        $this->assertSame($enrollment->id, $student->enrollments()->activeOn('2026-09-30')->firstOrFail()->id);
        $this->assertSame($replacement->id, $student->enrollments()->activeOn('2026-10-01')->firstOrFail()->id);
    }

    public function test_rejection_requires_and_preserves_a_reason_without_changing_the_contract(): void
    {
        [$student, $enrollment] = $this->studentWithEnrollment();
        $request = ContractChangeRequest::create($this->requestAttributes($student, $enrollment));
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->patch(route('staff.contract-change-requests.update', $request), [
            'decision' => ApplicationStatus::Rejected->value,
        ])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($teacher)->patch(route('staff.contract-change-requests.update', $request), [
            'decision' => ApplicationStatus::Rejected->value,
            'rejection_reason' => '現在の契約期間では変更できません。',
        ])->assertRedirectToRoute('staff.contract-change-requests.show', $request);

        $this->assertSame(ApplicationStatus::Rejected, $request->fresh()->status);
        $this->assertSame('現在の契約期間では変更できません。', $request->fresh()->rejection_reason);
        $this->assertNull($enrollment->fresh()->ends_on);
        $this->assertDatabaseCount('lesson_enrollments', 1);
    }

    public function test_student_cannot_review_a_contract_change_request(): void
    {
        [$student, $enrollment] = $this->studentWithEnrollment();
        $request = ContractChangeRequest::create($this->requestAttributes($student, $enrollment));

        $this->actingAs($student->user)->patch(route('staff.contract-change-requests.update', $request), [
            'decision' => ApplicationStatus::Approved->value,
        ])->assertForbidden();

        $this->assertSame(ApplicationStatus::Pending, $request->fresh()->status);
    }

    /** @return array{StudentProfile, LessonEnrollment, TeacherProfile, Venue} */
    private function studentWithEnrollment(): array
    {
        $student = StudentProfile::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $venue = Venue::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->for($teacher)->for($venue)->create([
            'weekday' => 2,
            'starts_at_time' => '18:00',
            'starts_on' => '2026-01-01',
            'status' => EnrollmentStatus::Active,
        ]);

        return [$student, $enrollment, $teacher, $venue];
    }

    /** @return array<string, mixed> */
    private function requestAttributes(StudentProfile $student, LessonEnrollment $enrollment): array
    {
        return [
            'student_profile_id' => $student->id,
            'lesson_enrollment_id' => $enrollment->id,
            'type' => ContractChangeType::MonthlyLessons,
            'effective_on' => today()->addMonth()->toDateString(),
            'before_values' => ['monthly_lesson_limit' => 4],
            'after_values' => ['monthly_lesson_limit' => 2],
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
        ];
    }
}
