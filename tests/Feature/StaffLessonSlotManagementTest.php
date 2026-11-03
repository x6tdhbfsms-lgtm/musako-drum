<?php

namespace Tests\Feature;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\Course;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffLessonSlotManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lesson_slot_list_shows_names_for_pending_and_confirmed_students_only(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create();
        $pendingStudent = StudentProfile::factory()->for(User::factory()->state(['name' => '申請中の生徒']), 'user')->create();
        $confirmedStudent = StudentProfile::factory()->for(User::factory()->state(['name' => '確定した生徒']), 'user')->create();
        $cancelledStudent = StudentProfile::factory()->for(User::factory()->state(['name' => '取消済みの生徒']), 'user')->create();
        ReservationRequest::factory()->for($slot)->for($pendingStudent, 'studentProfile')->create(['status' => ReservationStatus::Pending]);
        ReservationRequest::factory()->for($slot)->for($confirmedStudent, 'studentProfile')->create(['status' => ReservationStatus::Approved]);
        ReservationRequest::factory()->for($slot)->for($cancelledStudent, 'studentProfile')->create(['status' => ReservationStatus::Cancelled]);

        $response = $this->actingAs($teacher->user)->get(route('staff.lesson-slots.index'));

        $response->assertSeeText('申請中の生徒')
            ->assertSeeText('確定した生徒')
            ->assertSeeText('申請中')
            ->assertSeeText('確定')
            ->assertDontSeeText('取消済みの生徒')
            ->assertSeeText('予約2名');
    }

    public function test_lesson_slot_list_hides_slots_that_have_already_started(): void
    {
        $this->travelTo('2026-10-20 15:00:00');
        $teacher = TeacherProfile::factory()->create();
        $pastSlot = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => '2026-10-20 14:00:00',
            'ends_at' => '2026-10-20 15:00:00',
        ]);
        $upcomingSlot = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => '2026-10-20 16:00:00',
            'ends_at' => '2026-10-20 17:00:00',
        ]);

        $this->actingAs($teacher->user)
            ->get(route('staff.lesson-slots.index'))
            ->assertOk()
            ->assertViewHas('lessonSlots', fn ($slots): bool => $slots->pluck('id')->all() === [$upcomingSlot->id])
            ->assertSeeText('16:00〜17:00')
            ->assertDontSeeText('14:00〜15:00');
    }

    public function test_teacher_can_create_a_lesson_slot_for_themselves(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $teacher = TeacherProfile::factory()->create();
        $course = Course::factory()->create();
        $venue = Venue::factory()->create();

        $this->actingAs($teacher->user)
            ->post(route('staff.lesson-slots.store'), [
                'course_id' => $course->id,
                'venue_id' => $venue->id,
                'starts_at' => '2026-09-20 14:00',
                'ends_at' => '2026-09-20 15:00',
                'capacity' => 2,
                'notes' => '初回枠',
            ])
            ->assertRedirectToRoute('staff.lesson-slots.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('lesson_slots', [
            'teacher_profile_id' => $teacher->id,
            'course_id' => $course->id,
            'venue_id' => $venue->id,
            'capacity' => 2,
            'status' => LessonSlotStatus::Open->value,
        ]);
    }

    public function test_capacity_rechecks_reservations_added_after_form_validation(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create([
            'capacity' => 2, 'starts_at' => '2026-09-20 14:00:00', 'ends_at' => '2026-09-20 15:00:00',
        ]);
        ReservationRequest::factory()->for($slot)->create(['status' => ReservationStatus::Approved]);
        $pending = ReservationRequest::factory()->for($slot)->create();
        $interleaved = false;
        DB::listen(function ($query) use (&$interleaved, $pending): void {
            if (! $interleaved && str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'trial_lesson_requests')) {
                $interleaved = true;
                $pending->update(['status' => ReservationStatus::Approved]);
            }
        });

        $this->actingAs($teacher->user)->put(route('staff.lesson-slots.update', $slot), [
            'capacity' => 1, 'status' => 'open', 'starts_at' => '2026-09-20 14:00:00', 'ends_at' => '2026-09-20 15:00:00',
        ])->assertSessionHasErrors('capacity');

        $this->assertTrue($interleaved);
        $this->assertSame(2, $slot->fresh()->capacity);
        $this->assertSame(2, $slot->reservationRequests()->where('status', ReservationStatus::Approved)->count());
    }

    public function test_teacher_cannot_edit_another_teachers_slot(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($otherTeacher)->create();

        $this->actingAs($teacher->user)
            ->get(route('staff.lesson-slots.edit', $slot))
            ->assertForbidden();
    }

    public function test_slot_end_time_must_be_after_start_time(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $teacher = TeacherProfile::factory()->create();

        $this->actingAs($teacher->user)
            ->post(route('staff.lesson-slots.store'), [
                'starts_at' => '2026-09-20 15:00',
                'ends_at' => '2026-09-20 14:00',
                'capacity' => 1,
            ])
            ->assertSessionHasErrors('ends_at');

        $this->assertDatabaseCount('lesson_slots', 0);
    }

    public function test_slot_with_a_reservation_request_cannot_be_deleted(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create();
        ReservationRequest::factory()->for($slot)->create();

        $this->actingAs($teacher->user)
            ->delete(route('staff.lesson-slots.destroy', $slot))
            ->assertSessionHas('error');

        $this->assertModelExists($slot);
    }

    public function test_capacity_cannot_be_reduced_below_approved_reservations(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create(['capacity' => 2]);
        ReservationRequest::factory()->count(2)->for($slot)->create(['status' => ReservationStatus::Approved]);

        $this->actingAs($teacher->user)
            ->put(route('staff.lesson-slots.update', $slot), [
                'starts_at' => $slot->starts_at->format('Y-m-d H:i'),
                'ends_at' => $slot->ends_at->format('Y-m-d H:i'),
                'capacity' => 1,
                'status' => LessonSlotStatus::Open->value,
            ])
            ->assertSessionHasErrors('capacity');

        $this->assertSame(2, $slot->fresh()->capacity);
    }
}
