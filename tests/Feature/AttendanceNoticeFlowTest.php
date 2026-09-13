<?php

namespace Tests\Feature;

use App\Enums\AttendanceNoticeType;
use App\Enums\ReservationStatus;
use App\Models\AttendanceNotice;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendanceNoticeFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_can_submit_an_absence_notice_for_an_approved_reservation(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $student = StudentProfile::factory()->create();
        $reservation = $this->approvedReservation($student);

        $this->actingAs($student->user)
            ->put(route('student.attendance-notices.store', $reservation), [
                'type' => AttendanceNoticeType::Absence->value,
                'notes' => '体調不良です',
            ])
            ->assertRedirectToRoute('student.attendance-notices.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_notices', [
            'reservation_request_id' => $reservation->id,
            'type' => AttendanceNoticeType::Absence->value,
            'late_minutes' => null,
            'notes' => '体調不良です',
        ]);
    }

    public function test_second_notice_updates_the_existing_notice_instead_of_duplicating_it(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $student = StudentProfile::factory()->create();
        $reservation = $this->approvedReservation($student);
        AttendanceNotice::factory()->for($reservation)->create();

        $this->actingAs($student->user)
            ->put(route('student.attendance-notices.store', $reservation), [
                'type' => AttendanceNoticeType::Late->value,
                'late_minutes' => 15,
                'notes' => '電車遅延です',
            ])
            ->assertRedirectToRoute('student.attendance-notices.index');

        $this->assertDatabaseCount('attendance_notices', 1);
        $this->assertDatabaseHas('attendance_notices', [
            'reservation_request_id' => $reservation->id,
            'type' => AttendanceNoticeType::Late->value,
            'late_minutes' => 15,
            'notes' => '電車遅延です',
        ]);
    }

    public function test_late_notice_requires_minutes_or_an_arrival_time(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $student = StudentProfile::factory()->create();
        $reservation = $this->approvedReservation($student);

        $this->actingAs($student->user)
            ->put(route('student.attendance-notices.store', $reservation), [
                'type' => AttendanceNoticeType::Late->value,
            ])
            ->assertSessionHasErrors([
                'late_minutes' => '遅刻予定分数または到着予定時刻のどちらかを入力してください。',
            ]);

        $this->assertDatabaseCount('attendance_notices', 0);
    }

    public function test_arrival_time_must_be_during_the_lesson(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $student = StudentProfile::factory()->create();
        $reservation = $this->approvedReservation($student);

        $this->actingAs($student->user)
            ->put(route('student.attendance-notices.store', $reservation), [
                'type' => AttendanceNoticeType::Late->value,
                'expected_arrival_time' => '13:30',
            ])
            ->assertSessionHasErrors('expected_arrival_time');

        $this->assertDatabaseCount('attendance_notices', 0);
    }

    public function test_student_cannot_submit_a_notice_for_a_pending_or_another_students_reservation(): void
    {
        $student = StudentProfile::factory()->create();
        $pendingReservation = ReservationRequest::factory()->for($student)->create();
        $otherReservation = ReservationRequest::factory()->create(['status' => ReservationStatus::Approved]);

        $this->actingAs($student->user)
            ->put(route('student.attendance-notices.store', $pendingReservation), ['type' => AttendanceNoticeType::Absence->value])
            ->assertForbidden();
        $this->actingAs($student->user)
            ->put(route('student.attendance-notices.store', $otherReservation), ['type' => AttendanceNoticeType::Absence->value])
            ->assertForbidden();

        $this->assertDatabaseCount('attendance_notices', 0);
    }

    public function test_teacher_dashboard_shows_only_todays_notices_for_their_slots(): void
    {
        $this->travelTo('2026-09-12 09:00:00');
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $today = $this->approvedReservation(
            StudentProfile::factory()->create(['student_number' => 'TODAY']),
            LessonSlot::factory()->for($teacher)->create(['starts_at' => '2026-09-12 14:00:00', 'ends_at' => '2026-09-12 15:00:00']),
        );
        $other = $this->approvedReservation(
            StudentProfile::factory()->create(['student_number' => 'OTHER']),
            LessonSlot::factory()->for($otherTeacher)->create(['starts_at' => '2026-09-12 14:00:00', 'ends_at' => '2026-09-12 15:00:00']),
        );
        AttendanceNotice::factory()->for($today)->create(['type' => AttendanceNoticeType::Late, 'late_minutes' => 10]);
        AttendanceNotice::factory()->for($other)->create();

        $this->actingAs($teacher->user)
            ->get(route('staff.dashboard'))
            ->assertSee($today->studentProfile->user->name)
            ->assertDontSee($other->studentProfile->user->name)
            ->assertSee('10分遅れ');
    }

    private function approvedReservation(StudentProfile $student, ?LessonSlot $slot = null): ReservationRequest
    {
        return ReservationRequest::factory()
            ->for($student)
            ->for($slot ?? LessonSlot::factory()->create([
                'starts_at' => '2026-09-13 14:00:00',
                'ends_at' => '2026-09-13 15:00:00',
            ]))
            ->create(['status' => ReservationStatus::Approved]);
    }
}
