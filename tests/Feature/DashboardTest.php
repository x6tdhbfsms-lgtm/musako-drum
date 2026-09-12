<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_dashboard_shows_reservation_summary(): void
    {
        $student = StudentProfile::factory()->create();
        ReservationRequest::factory()->for($student)->create();
        ReservationRequest::factory()->for($student)->create(['status' => ReservationStatus::Approved]);

        $this->actingAs($student->user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee($student->user->name)
            ->assertSee('承認待ち')
            ->assertSee('確定予約');
    }

    public function test_teacher_dashboard_shows_only_their_pending_requests(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $ownReservation = ReservationRequest::factory()
            ->for(LessonSlot::factory()->for($teacher))
            ->create();
        ReservationRequest::factory()
            ->for(LessonSlot::factory()->for($otherTeacher))
            ->create();

        $this->actingAs($teacher->user)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee($ownReservation->studentProfile->user->name)
            ->assertSee('承認待ち');
    }
}
