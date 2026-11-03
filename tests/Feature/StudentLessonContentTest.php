<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StudentLessonContentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lesson_content_is_edited_from_each_reservation_and_only_nearest_upcoming_content_shows(): void
    {
        $this->travelTo('2026-12-01 09:00:00');
        $student = StudentProfile::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $nearestSlot = LessonSlot::factory()->create([
            'starts_at' => '2027-01-18 14:00:00',
            'ends_at' => '2027-01-18 15:00:00',
        ]);
        $nearestReservation = ReservationRequest::factory()->for($student)->for($nearestSlot)->create([
            'status' => ReservationStatus::Approved,
        ]);
        $laterSlot = LessonSlot::factory()->create([
            'starts_at' => '2027-01-20 14:00:00',
            'ends_at' => '2027-01-20 15:00:00',
        ]);
        $laterReservation = ReservationRequest::factory()->for($student)->for($laterSlot)->create([
            'status' => ReservationStatus::Approved,
        ]);

        $this->actingAs($admin)->get(route('staff.students.show', $student))
            ->assertOk()
            ->assertDontSee('直近のレッスン情報')
            ->assertSee('2027年1月18日 14:00');
        $this->actingAs($admin)->get(route('staff.reservations.show', $laterReservation))
            ->assertOk()
            ->assertSee('このレッスンの内容')
            ->assertSee('2027年1月20日 14:00 のレッスン専用の記録');
        $this->actingAs($admin)->patch(route('staff.students.reservations.lesson-content.update', [$student, $laterReservation]), [
            'lesson_content' => '1月20日専用のレッスン内容',
        ])->assertRedirect(route('staff.reservations.show', $laterReservation));

        $this->assertSame('1月20日専用のレッスン内容', $laterReservation->fresh()->lesson_content);
        $this->actingAs($student->user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('次回レッスンカルテ')
            ->assertSee('レッスン内容はまだ登録されていません。')
            ->assertDontSee('1月20日専用のレッスン内容');

        $this->actingAs($admin)->patch(route('staff.students.reservations.lesson-content.update', [$student, $nearestReservation]), [
            'lesson_content' => '1月18日専用のレッスン内容',
        ])->assertRedirect(route('staff.reservations.show', $nearestReservation));
        $this->actingAs($student->user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('1月18日専用のレッスン内容')
            ->assertDontSee('1月20日専用のレッスン内容');
    }

    public function test_past_lesson_content_is_not_used_for_the_next_lesson_chart(): void
    {
        $this->travelTo('2026-11-03 09:00:00');
        $student = StudentProfile::factory()->create();
        $previousSlot = LessonSlot::factory()->create([
            'starts_at' => '2026-10-27 14:00:00',
            'ends_at' => '2026-10-27 15:00:00',
        ]);
        ReservationRequest::factory()->for($student)->for($previousSlot)->create([
            'status' => ReservationStatus::Approved,
            'lesson_content' => '前回の練習内容：フィルイン',
        ]);
        $cancelledSlot = LessonSlot::factory()->create([
            'starts_at' => '2026-11-01 14:00:00',
            'ends_at' => '2026-11-01 15:00:00',
        ]);
        ReservationRequest::factory()->for($student)->for($cancelledSlot)->create([
            'status' => ReservationStatus::Cancelled,
            'lesson_content' => 'キャンセルされた内容',
        ]);

        $this->actingAs($student->user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('確定した今後のレッスンはありません。')
            ->assertDontSee('次回レッスンカルテ')
            ->assertDontSee('前回の練習内容：フィルイン')
            ->assertDontSee('キャンセルされた内容');
    }

    public function test_only_admin_can_update_content_for_their_students_upcoming_reservation(): void
    {
        $this->travelTo('2026-11-03 09:00:00');
        $student = StudentProfile::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $slot = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => '2026-11-10 14:00:00',
            'ends_at' => '2026-11-10 15:00:00',
        ]);
        $reservation = ReservationRequest::factory()->for($student)->for($slot)->create([
            'status' => ReservationStatus::Approved,
        ]);

        $this->actingAs($teacher->user)->patch(route('staff.students.reservations.lesson-content.update', [$student, $reservation]), [
            'lesson_content' => '講師からの入力',
        ])->assertForbidden();
        $this->actingAs($student->user)->patch(route('staff.students.reservations.lesson-content.update', [$student, $reservation]), [
            'lesson_content' => '生徒からの入力',
        ])->assertForbidden();

        $this->assertNull($reservation->fresh()->lesson_content);
    }
}
