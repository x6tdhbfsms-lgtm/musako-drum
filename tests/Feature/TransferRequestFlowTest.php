<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TransferRequestFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_can_request_a_transfer_at_the_previous_day_deadline(): void
    {
        $this->travelTo('2026-09-10 19:00:00');
        $student = StudentProfile::factory()->create();
        $original = $this->approvedReservation($student, '2026-09-11 14:00:00');
        $target = LessonSlot::factory()->create(['starts_at' => '2026-09-15 14:00:00', 'ends_at' => '2026-09-15 15:00:00']);

        $this->actingAs($student->user)
            ->get(route('student.transfer-requests.create', $original))
            ->assertOk()
            ->assertSee('振替先を選択');

        $this->actingAs($student->user)
            ->post(route('student.transfer-requests.store', $original), [
                'requested_lesson_slot_id' => $target->id,
                'reason' => '予定変更',
            ])
            ->assertRedirectToRoute('student.transfer-requests.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('transfer_requests', [
            'student_profile_id' => $student->id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $target->id,
            'status' => ApplicationStatus::Pending->value,
        ]);
    }

    public function test_student_cannot_request_a_transfer_after_the_previous_day_deadline(): void
    {
        $this->travelTo('2026-09-10 19:00:01');
        $student = StudentProfile::factory()->create();
        $original = $this->approvedReservation($student, '2026-09-11 14:00:00');
        $target = LessonSlot::factory()->create(['starts_at' => '2026-09-15 14:00:00', 'ends_at' => '2026-09-15 15:00:00']);

        $this->actingAs($student->user)
            ->post(route('student.transfer-requests.store', $original), ['requested_lesson_slot_id' => $target->id])
            ->assertSessionHasErrors([
                'reservation' => '振替申請期限（前日19:00）を過ぎています。',
            ]);

        $this->assertDatabaseCount('transfer_requests', 0);
    }

    public function test_duplicate_pending_transfer_request_is_rejected(): void
    {
        $this->travelTo('2026-09-10 18:00:00');
        $student = StudentProfile::factory()->create();
        $original = $this->approvedReservation($student, '2026-09-11 14:00:00');
        $firstTarget = LessonSlot::factory()->create(['starts_at' => '2026-09-15 14:00:00', 'ends_at' => '2026-09-15 15:00:00']);
        $secondTarget = LessonSlot::factory()->create(['starts_at' => '2026-09-16 14:00:00', 'ends_at' => '2026-09-16 15:00:00']);
        TransferRequest::factory()->create([
            'student_profile_id' => $student->id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $firstTarget->id,
        ]);

        $this->actingAs($student->user)
            ->post(route('student.transfer-requests.store', $original), ['requested_lesson_slot_id' => $secondTarget->id])
            ->assertSessionHasErrors('reservation');

        $this->assertDatabaseCount('transfer_requests', 1);
    }

    public function test_student_cannot_request_a_transfer_for_another_students_reservation(): void
    {
        $student = StudentProfile::factory()->create();
        $otherReservation = $this->approvedReservation(StudentProfile::factory()->create(), now()->addWeek()->format('Y-m-d H:i:s'));
        $target = LessonSlot::factory()->create();

        $this->actingAs($student->user)
            ->post(route('student.transfer-requests.store', $otherReservation), ['requested_lesson_slot_id' => $target->id])
            ->assertForbidden();
    }

    public function test_approval_cancels_the_original_and_creates_an_approved_target_reservation(): void
    {
        $this->travelTo('2026-09-10 18:00:00');
        $teacher = TeacherProfile::factory()->create();
        $student = StudentProfile::factory()->create();
        $original = $this->approvedReservation($student, '2026-09-12 14:00:00', $teacher);
        $target = LessonSlot::factory()->for($teacher)->create(['starts_at' => '2026-09-20 14:00:00', 'ends_at' => '2026-09-20 15:00:00']);
        $transfer = TransferRequest::factory()->create([
            'student_profile_id' => $student->id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $target->id,
        ]);

        $this->actingAs($teacher->user)
            ->patch(route('staff.transfer-requests.update', $transfer), [
                'decision' => ApplicationStatus::Approved->value,
                'staff_note' => '承認しました',
            ])
            ->assertRedirectToRoute('staff.transfer-requests.show', $transfer);

        $this->assertSame(ReservationStatus::Cancelled, $original->fresh()->status);
        $this->assertDatabaseHas('reservation_requests', [
            'student_profile_id' => $student->id,
            'lesson_slot_id' => $target->id,
            'status' => ReservationStatus::Approved->value,
        ]);
        $this->assertSame(ApplicationStatus::Approved, $transfer->fresh()->status);
        $this->assertNotNull($transfer->fresh()->resulting_reservation_request_id);
    }

    public function test_rejection_preserves_the_original_reservation_and_history(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $student = StudentProfile::factory()->create();
        $original = $this->approvedReservation($student, now()->addWeek()->format('Y-m-d H:i:s'), $teacher);
        $target = LessonSlot::factory()->for($teacher)->create();
        $transfer = TransferRequest::factory()->create([
            'student_profile_id' => $student->id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $target->id,
        ]);

        $this->actingAs($teacher->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => ApplicationStatus::Rejected->value])
            ->assertRedirectToRoute('staff.transfer-requests.show', $transfer);

        $this->assertSame(ReservationStatus::Approved, $original->fresh()->status);
        $this->assertSame(ApplicationStatus::Rejected, $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->resulting_reservation_request_id);
    }

    public function test_unrelated_teacher_cannot_review_the_transfer_request(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $unrelatedTeacher = TeacherProfile::factory()->create();
        $student = StudentProfile::factory()->create();
        $original = $this->approvedReservation($student, now()->addWeek()->format('Y-m-d H:i:s'), $teacher);
        $target = LessonSlot::factory()->for($teacher)->create();
        $transfer = TransferRequest::factory()->create([
            'student_profile_id' => $student->id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $target->id,
        ]);

        $this->actingAs($unrelatedTeacher->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => ApplicationStatus::Approved->value])
            ->assertForbidden();
    }

    public function test_only_one_of_two_competing_transfers_can_take_the_last_slot(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $teacher = TeacherProfile::factory()->create();
        $target = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => '2026-09-20 14:00:00',
            'ends_at' => '2026-09-20 15:00:00',
            'capacity' => 1,
        ]);
        $firstStudent = StudentProfile::factory()->create();
        $secondStudent = StudentProfile::factory()->create();
        $firstOriginal = $this->approvedReservation($firstStudent, '2026-09-15 14:00:00', $teacher);
        $secondOriginal = $this->approvedReservation($secondStudent, '2026-09-16 14:00:00', $teacher);
        $firstTransfer = TransferRequest::factory()->create([
            'student_profile_id' => $firstStudent->id,
            'original_reservation_request_id' => $firstOriginal->id,
            'requested_lesson_slot_id' => $target->id,
        ]);
        $secondTransfer = TransferRequest::factory()->create([
            'student_profile_id' => $secondStudent->id,
            'original_reservation_request_id' => $secondOriginal->id,
            'requested_lesson_slot_id' => $target->id,
        ]);
        $this->actingAs($teacher->user)->patch(route('staff.transfer-requests.update', $firstTransfer), [
            'decision' => ApplicationStatus::Approved->value,
        ]);

        $this->actingAs($teacher->user)
            ->patch(route('staff.transfer-requests.update', $secondTransfer), [
                'decision' => ApplicationStatus::Approved->value,
            ])
            ->assertSessionHasErrors('transfer_request');

        $this->assertSame(ApplicationStatus::Approved, $firstTransfer->fresh()->status);
        $this->assertSame(ApplicationStatus::Pending, $secondTransfer->fresh()->status);
        $this->assertSame(ReservationStatus::Approved, $secondOriginal->fresh()->status);
        $this->assertSame(1, $target->reservationRequests()->where('status', ReservationStatus::Approved)->count());
    }

    private function approvedReservation(
        StudentProfile $student,
        string $startsAt,
        ?TeacherProfile $teacher = null,
    ): ReservationRequest {
        $starts = CarbonImmutable::parse($startsAt, config('app.timezone'));
        $slot = LessonSlot::factory()
            ->for($teacher ?? TeacherProfile::factory()->create())
            ->create(['starts_at' => $starts, 'ends_at' => $starts->addHour()]);

        return ReservationRequest::factory()->for($student)->for($slot)->create(['status' => ReservationStatus::Approved, 'studio_fee_amount' => 1610, 'studio_fee_priced_on' => $starts->toDateString()]);
    }
}
