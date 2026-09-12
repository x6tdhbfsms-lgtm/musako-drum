<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Models\Inquiry;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InquiryAndStudentDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_can_create_and_view_own_inquiry_history(): void
    {
        $student = StudentProfile::factory()->create();

        $this->actingAs($student->user)->post(route('student.inquiries.store'), [
            'category' => 'lesson',
            'subject' => '練習内容について',
            'body' => '次回までの練習内容を確認したいです。',
        ])->assertRedirectToRoute('student.inquiries.index');

        $inquiry = Inquiry::query()->firstOrFail();
        $this->assertSame(InquiryStatus::Open, $inquiry->status);
        $this->actingAs($student->user)->get(route('student.inquiries.index'))
            ->assertSee('練習内容について')
            ->assertSee('未対応');
        $this->actingAs($student->user)->get(route('student.inquiries.show', $inquiry))
            ->assertSee('次回までの練習内容を確認したいです。');
    }

    public function test_student_cannot_view_another_students_inquiry(): void
    {
        $owner = StudentProfile::factory()->create();
        $other = StudentProfile::factory()->create();
        $inquiry = $this->inquiry($owner);

        $this->actingAs($other->user)->get(route('student.inquiries.show', $inquiry))->assertForbidden();
    }

    public function test_teacher_can_update_inquiry_status_and_student_can_see_the_result(): void
    {
        $student = StudentProfile::factory()->create();
        $inquiry = $this->inquiry($student);
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->patch(route('staff.inquiries.update', $inquiry), [
            'status' => InquiryStatus::InProgress->value,
            'staff_note' => '確認してご連絡します。',
        ])->assertRedirectToRoute('staff.inquiries.show', $inquiry);

        $this->assertSame(InquiryStatus::InProgress, $inquiry->fresh()->status);
        $this->assertSame($teacher->id, $inquiry->fresh()->handled_by_user_id);
        $this->actingAs($student->user)->get(route('student.inquiries.show', $inquiry))
            ->assertSee('対応中')
            ->assertSee('確認してご連絡します。');
    }

    public function test_student_cannot_use_staff_inquiry_management(): void
    {
        $student = StudentProfile::factory()->create();
        $inquiry = $this->inquiry($student);

        $this->actingAs($student->user)->patch(route('staff.inquiries.update', $inquiry), [
            'status' => InquiryStatus::Resolved->value,
        ])->assertForbidden();

        $this->assertSame(InquiryStatus::Open, $inquiry->fresh()->status);
    }

    public function test_assigned_teacher_and_admin_can_view_student_detail_but_other_teacher_cannot(): void
    {
        $student = StudentProfile::factory()->create(['phone' => '090-1234-5678']);
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->for($teacher)->create(['starts_on' => today()->subMonth()]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($teacher->user)->get(route('staff.students.show', $student))
            ->assertOk()->assertSee($student->user->name)->assertSee('090-1234-5678');
        $this->actingAs($admin)->get(route('staff.students.show', $student))->assertOk();
        $this->actingAs($otherTeacher->user)->get(route('staff.students.show', $student))->assertForbidden();
    }

    public function test_staff_reservation_detail_links_to_student_and_shows_contract_information(): void
    {
        $student = StudentProfile::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $venue = Venue::factory()->create(['name' => '武蔵小金井']);
        $enrollment = LessonEnrollment::factory()->for($student)->for($teacher)->for($venue)->create([
            'monthly_lesson_limit' => 4,
            'starts_on' => today()->subMonth(),
        ]);
        $slot = LessonSlot::factory()->for($teacher)->for($venue)->for($enrollment->course)->create();
        $reservation = ReservationRequest::factory()->for($student)->for($slot)->for($enrollment, 'lessonEnrollment')->create([
            'status' => ReservationStatus::Approved,
        ]);

        $this->actingAs($teacher->user)->get(route('staff.reservations.show', $reservation))
            ->assertOk()
            ->assertSee('4回／月')
            ->assertSee('武蔵小金井')
            ->assertSee(route('staff.students.show', $student), escape: false);
    }

    public function test_teacher_can_open_student_detail_from_their_reservation_without_a_contract_assignment(): void
    {
        $student = StudentProfile::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create();
        ReservationRequest::factory()->for($student)->for($slot)->create();

        $this->actingAs($teacher->user)->get(route('staff.students.show', $student))
            ->assertOk()
            ->assertSee($student->user->name);
    }

    private function inquiry(StudentProfile $student): Inquiry
    {
        return Inquiry::create([
            'student_profile_id' => $student->id,
            'category' => 'reservation',
            'subject' => '予約について',
            'body' => '予約を確認したいです。',
            'status' => InquiryStatus::Open,
            'requested_at' => now(),
        ]);
    }
}
