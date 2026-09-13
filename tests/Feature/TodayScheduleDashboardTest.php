<?php

namespace Tests\Feature;

use App\Enums\AttendanceNoticeType;
use App\Enums\ReservationStatus;
use App\Models\AttendanceNotice;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TodayScheduleDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_teacher_dashboard_shows_todays_lessons_and_statuses_only_for_their_schedule(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $late = $this->reservation('2026-09-15 10:00:00', $teacher, '山田さん');
        AttendanceNotice::factory()->for($late)->create(['type' => AttendanceNoticeType::Late, 'late_minutes' => 15]);
        $transfer = $this->reservation('2026-09-15 12:00:00', $teacher, '佐藤さん');
        TransferRequest::factory()->create([
            'student_profile_id' => $transfer->student_profile_id,
            'original_reservation_request_id' => ReservationRequest::factory()->create()->id,
            'requested_lesson_slot_id' => $transfer->lesson_slot_id,
            'resulting_reservation_request_id' => $transfer->id,
            'status' => 'approved',
        ]);
        $this->reservation('2026-09-15 14:00:00', $otherTeacher, '別講師の生徒');

        $this->actingAs($teacher->user)
            ->get(route('staff.dashboard', ['view' => 'day', 'date' => '2026-09-15']))
            ->assertSee('本日のレッスン')
            ->assertSee('山田さん')
            ->assertSee('遅刻 15分')
            ->assertSee('佐藤さん')
            ->assertSee('振替')
            ->assertDontSee('別講師の生徒');
    }

    private function reservation(string $startsAt, TeacherProfile $teacher, string $studentName): ReservationRequest
    {
        $student = StudentProfile::factory()->create();
        $student->user->update(['name' => $studentName]);
        $starts = CarbonImmutable::parse($startsAt, config('app.timezone'));
        $slot = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
        ]);

        return ReservationRequest::factory()->for($student)->for($slot)->create(['status' => ReservationStatus::Approved]);
    }
}
