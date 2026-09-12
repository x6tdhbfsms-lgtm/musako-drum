<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffReservationReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_teacher_can_approve_a_pending_request_for_their_slot(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create();
        $reservation = ReservationRequest::factory()->for($slot)->create();

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $reservation), [
                'decision' => ReservationStatus::Approved->value,
                'staff_note' => '承認しました',
            ])
            ->assertRedirectToRoute('staff.reservations.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reservation_requests', [
            'id' => $reservation->id,
            'status' => ReservationStatus::Approved->value,
            'reviewed_by_user_id' => $teacher->user_id,
            'staff_note' => '承認しました',
        ]);
        $this->assertNotNull($reservation->fresh()->reviewed_at);
    }

    public function test_teacher_can_reject_a_pending_request_for_their_slot(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $reservation = ReservationRequest::factory()
            ->for(LessonSlot::factory()->for($teacher))
            ->create();

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $reservation), [
                'decision' => ReservationStatus::Rejected->value,
            ])
            ->assertRedirectToRoute('staff.reservations.index');

        $this->assertSame(ReservationStatus::Rejected, $reservation->fresh()->status);
    }

    public function test_teacher_cannot_review_a_request_for_another_teachers_slot(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $reservation = ReservationRequest::factory()
            ->for(LessonSlot::factory()->for($otherTeacher))
            ->create();

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $reservation), [
                'decision' => ReservationStatus::Approved->value,
            ])
            ->assertForbidden();
    }

    public function test_teacher_cannot_approve_more_requests_than_slot_capacity(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create(['capacity' => 1]);
        ReservationRequest::factory()->for($slot)->create(['status' => ReservationStatus::Approved]);
        $pending = ReservationRequest::factory()->for($slot)->create();

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $pending), [
                'decision' => ReservationStatus::Approved->value,
            ])
            ->assertSessionHasErrors('reservation');

        $this->assertSame(ReservationStatus::Pending, $pending->fresh()->status);
    }

    public function test_processed_request_cannot_be_reviewed_again(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $reservation = ReservationRequest::factory()
            ->for(LessonSlot::factory()->for($teacher))
            ->create(['status' => ReservationStatus::Rejected]);

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $reservation), [
                'decision' => ReservationStatus::Approved->value,
            ])
            ->assertSessionHasErrors('reservation');

        $this->assertSame(ReservationStatus::Rejected, $reservation->fresh()->status);
    }
}
