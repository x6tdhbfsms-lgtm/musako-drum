<?php

namespace Tests\Feature;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StudentReservationFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_can_see_only_open_future_slots_on_calendar(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $student = StudentProfile::factory()->create();
        $available = LessonSlot::factory()->create(['starts_at' => '2026-09-20 14:00', 'ends_at' => '2026-09-20 15:00']);
        $closed = LessonSlot::factory()->create([
            'starts_at' => '2026-09-21 14:00',
            'ends_at' => '2026-09-21 15:00',
            'status' => LessonSlotStatus::Closed,
        ]);
        $past = LessonSlot::factory()->create(['starts_at' => '2026-09-01 14:00', 'ends_at' => '2026-09-01 15:00']);

        $this->actingAs($student->user)
            ->get(route('student.lesson-slots.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee($available->starts_at->format('H:i'))
            ->assertDontSee(route('student.reservations.store', $closed), false)
            ->assertDontSee(route('student.reservations.store', $past), false);
    }

    public function test_student_can_submit_a_pending_reservation_request(): void
    {
        $student = StudentProfile::factory()->create();
        $slot = LessonSlot::factory()->create();

        $this->actingAs($student->user)
            ->post(route('student.reservations.store', $slot), ['student_note' => '午後を希望します'])
            ->assertRedirectToRoute('student.reservations.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reservation_requests', [
            'student_profile_id' => $student->id,
            'lesson_slot_id' => $slot->id,
            'status' => ReservationStatus::Pending->value,
            'student_note' => '午後を希望します',
        ]);
    }

    public function test_same_student_cannot_request_the_same_slot_twice(): void
    {
        $student = StudentProfile::factory()->create();
        $slot = LessonSlot::factory()->create(['capacity' => 2]);
        ReservationRequest::factory()->for($student)->for($slot)->create();

        $this->actingAs($student->user)
            ->from(route('student.lesson-slots.index'))
            ->post(route('student.reservations.store', $slot))
            ->assertRedirectToRoute('student.lesson-slots.index')
            ->assertSessionHasErrors('lesson_slot');

        $this->assertDatabaseCount('reservation_requests', 1);
    }

    public function test_student_cannot_request_a_full_slot(): void
    {
        $student = StudentProfile::factory()->create();
        $slot = LessonSlot::factory()->create(['capacity' => 1]);
        ReservationRequest::factory()->for($slot)->create(['status' => ReservationStatus::Approved]);

        $this->actingAs($student->user)
            ->post(route('student.reservations.store', $slot))
            ->assertSessionHasErrors('lesson_slot');

        $this->assertDatabaseMissing('reservation_requests', [
            'student_profile_id' => $student->id,
            'lesson_slot_id' => $slot->id,
        ]);
    }

    public function test_student_can_cancel_their_own_approved_future_reservation(): void
    {
        $student = StudentProfile::factory()->create();
        $reservation = ReservationRequest::factory()->for($student)->create(['status' => ReservationStatus::Approved]);

        $this->actingAs($student->user)
            ->delete(route('student.reservations.destroy', $reservation), ['cancellation_reason' => '都合が悪くなったため'])
            ->assertRedirectToRoute('student.reservations.index');

        $this->assertDatabaseHas('reservation_requests', [
            'id' => $reservation->id,
            'status' => ReservationStatus::Cancelled->value,
            'cancellation_reason' => '都合が悪くなったため',
        ]);
        $this->assertNotNull($reservation->fresh()->cancelled_at);
    }

    public function test_student_cannot_cancel_another_students_reservation(): void
    {
        $student = StudentProfile::factory()->create();
        $reservation = ReservationRequest::factory()->create();

        $this->actingAs($student->user)
            ->delete(route('student.reservations.destroy', $reservation))
            ->assertForbidden();
    }
}
