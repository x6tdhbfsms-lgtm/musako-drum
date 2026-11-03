<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MembershipRequestType;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
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
            ->assertSee('ログイン名：')
            ->assertSee($student->user->login_name)
            ->assertSee('実施済み')
            ->assertSee('申請中');
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
            ->get(route('staff.dashboard', ['date' => $ownReservation->lessonSlot->starts_at->format('Y-m-d')]))
            ->assertOk()
            ->assertSee($ownReservation->studentProfile->user->name)
            ->assertSee('承認待ち');
    }

    public function test_staff_dashboard_shows_pending_transfer_and_membership_counts(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $student = StudentProfile::factory()->create();
        $originalReservation = ReservationRequest::factory()
            ->for($student)
            ->for(LessonSlot::factory()->for($teacher))
            ->create(['status' => ReservationStatus::Approved]);
        $targetSlot = LessonSlot::factory()->for($teacher)->create();
        TransferRequest::factory()->for($student)->create([
            'original_reservation_request_id' => $originalReservation->id,
            'requested_lesson_slot_id' => $targetSlot->id,
        ]);
        TransferRequest::factory()->for($student)->for($originalReservation, 'originalReservationRequest')->create([
            'status' => ApplicationStatus::Approved,
            'requested_lesson_slot_id' => null,
            'resulting_reservation_request_id' => null,
        ]);
        MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Pause,
        ]);

        $this->actingAs($teacher->user)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('振替の承認待ち')
            ->assertSee('保留中のレッスン')
            ->assertViewHas('deferredTransferCount', 1)
            ->assertSee('在籍申請の承認待ち');
    }

    public function test_staff_dashboard_highlights_deferred_lessons_from_previous_month(): void
    {
        $this->travelTo('2026-12-01 09:00:00');
        $teacher = TeacherProfile::factory()->create();
        $student = StudentProfile::factory()->create();
        $novemberSlot = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => '2026-11-10 10:00:00',
            'ends_at' => '2026-11-10 11:00:00',
        ]);
        $novemberReservation = ReservationRequest::factory()->for($student)->for($novemberSlot)->create([
            'status' => ReservationStatus::Cancelled,
            'lesson_entitlement_month' => '2026-11-01',
        ]);
        TransferRequest::factory()->for($student)->for($novemberReservation, 'originalReservationRequest')->create([
            'status' => ApplicationStatus::Approved,
            'requested_lesson_slot_id' => null,
            'resulting_reservation_request_id' => null,
        ]);

        $decemberSlot = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => '2026-12-10 10:00:00',
            'ends_at' => '2026-12-10 11:00:00',
        ]);
        $decemberReservation = ReservationRequest::factory()->for($student)->for($decemberSlot)->create([
            'status' => ReservationStatus::Cancelled,
            'lesson_entitlement_month' => '2026-12-01',
        ]);
        TransferRequest::factory()->for($student)->for($decemberReservation, 'originalReservationRequest')->create([
            'status' => ApplicationStatus::Approved,
            'requested_lesson_slot_id' => null,
            'resulting_reservation_request_id' => null,
        ]);

        $this->actingAs($teacher->user)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertViewHas('deferredTransferCount', 2)
            ->assertViewHas('overdueDeferredTransferCount', 1)
            ->assertSee('前月以前の保留が1件あります')
            ->assertSee('前月以前の保留あり')
            ->assertDontSee('予約の承認待ち');
    }
}
