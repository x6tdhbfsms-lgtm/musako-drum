<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceNoticeType;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Enums\StudentCalendarStatus;
use App\Models\AttendanceNotice;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CalendarDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_calendar_distinguishes_every_lesson_state(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $student = StudentProfile::factory()->create();
        $otherStudent = StudentProfile::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $available = $this->slot($teacher, '2026-09-10 10:00:00');
        $full = $this->slot($teacher, '2026-09-11 10:00:00', ['capacity' => 1]);
        ReservationRequest::factory()->for($otherStudent)->for($full)->create(['status' => ReservationStatus::Approved]);
        $this->slot($teacher, '2026-09-12 10:00:00', ['status' => LessonSlotStatus::Cancelled]);
        $pending = $this->reservation($student, $teacher, '2026-09-13 10:00:00', ReservationStatus::Pending);
        $approved = $this->reservation($student, $teacher, '2026-09-14 10:00:00', ReservationStatus::Approved);
        $absence = $this->reservation($student, $teacher, '2026-09-15 10:00:00', ReservationStatus::Approved);
        AttendanceNotice::factory()->for($absence)->create(['type' => AttendanceNoticeType::Absence]);
        $late = $this->reservation($student, $teacher, '2026-09-16 10:00:00', ReservationStatus::Approved);
        AttendanceNotice::factory()->for($late)->create(['type' => AttendanceNoticeType::Late, 'late_minutes' => 10]);
        $transferTarget = $this->slot($teacher, '2026-09-17 10:00:00');
        $transferSource = $this->reservation($student, $teacher, '2026-09-17 12:00:00', ReservationStatus::Approved);
        TransferRequest::factory()->for($student)->create([
            'original_reservation_request_id' => $transferSource->id,
            'requested_lesson_slot_id' => $transferTarget->id,
        ]);
        $transferResult = $this->reservation($student, $teacher, '2026-09-18 10:00:00', ReservationStatus::Approved);
        $transferOriginal = $this->reservation($student, $teacher, '2026-09-19 10:00:00', ReservationStatus::Cancelled);
        TransferRequest::factory()->for($student)->create([
            'original_reservation_request_id' => $transferOriginal->id,
            'requested_lesson_slot_id' => $transferResult->lesson_slot_id,
            'resulting_reservation_request_id' => $transferResult->id,
            'status' => ApplicationStatus::Approved,
        ]);

        $this->actingAs($student->user)
            ->get(route('student.dashboard', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('レッスンカレンダー')
            ->assertDontSee(route('student.reservations.store', $available), escape: false)
            ->assertDontSee(route('student.membership-status-requests.index', ['type' => 'pause']), escape: false)
            ->assertDontSee(route('student.membership-status-requests.index', ['type' => 'withdraw']), escape: false)
            ->assertViewHas('nextReservation', fn ($next) => $next->is($approved))
            ->assertViewHas('calendarEntriesByDay', function (Collection $days): bool {
                $statuses = $days->flatten(1)->pluck('status');

                return $statuses->count() === 8
                    && ! $statuses->contains(StudentCalendarStatus::Available)
                    && ! $statuses->contains(StudentCalendarStatus::Full)
                    && $statuses->contains(StudentCalendarStatus::TransferPending)
                    && $statuses->contains(StudentCalendarStatus::TransferApproved);
            });

        $this->assertSame(ReservationStatus::Pending, $pending->fresh()->status);
    }

    public function test_student_without_profile_has_no_calendar_entries(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $this->slot(TeacherProfile::factory()->create(), '2026-09-10 10:00:00');
        $this->actingAs(User::factory()->create())
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertViewHas('calendarEntriesByDay', fn (Collection $days): bool => $days->isEmpty())
            ->assertViewHas('nextReservation', null);
    }

    public function test_default_staff_week_shows_all_nine_daily_lessons(): void
    {
        $this->travelTo('2026-09-10 09:00:00');
        $teacher = TeacherProfile::factory()->create();
        $names = [];
        foreach (range(10, 18) as $hour) {
            $name = '９コマ確認'.$hour;
            $student = StudentProfile::factory()->create(['user_id' => User::factory()->create(['name' => $name])]);
            $this->reservation($student, $teacher, '2026-09-10 '.$hour.':00:00', ReservationStatus::Approved);
            $names[] = $name;
        }
        $response = $this->actingAs($teacher->user)->get(route('staff.dashboard'))->assertOk()->assertViewHas('calendarView', 'week');
        foreach ($names as $name) {
            $response->assertSee($name);
        }
    }

    public function test_student_reservation_detail_exposes_actions_and_forbids_another_student(): void
    {
        $student = StudentProfile::factory()->create();
        $reservation = $this->reservation($student, TeacherProfile::factory()->create(), now()->addWeek()->format('Y-m-d H:i:s'), ReservationStatus::Approved);
        $otherStudent = StudentProfile::factory()->create();

        $this->actingAs($student->user)
            ->get(route('student.reservations.show', $reservation))
            ->assertOk()
            ->assertSee('お休み連絡')
            ->assertSee('遅刻連絡')
            ->assertSee('振替を申請する')
            ->assertSee('予約をキャンセル');

        $this->actingAs($otherStudent->user)
            ->get(route('student.reservations.show', $reservation))
            ->assertForbidden();
    }

    public function test_rejected_reservation_slot_is_not_shown_as_available_again(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $student = StudentProfile::factory()->create();
        $reservation = $this->reservation($student, TeacherProfile::factory()->create(), '2026-09-10 10:00:00', ReservationStatus::Rejected);

        $this->actingAs($student->user)
            ->get(route('student.dashboard', ['month' => '2026-09']))
            ->assertOk()
            ->assertDontSee(route('student.reservations.store', $reservation->lessonSlot), escape: false)
            ->assertViewHas('calendarEntriesByDay', function (Collection $days) use ($reservation): bool {
                $entry = $days->flatten(1)->first(fn (array $entry): bool => $entry['slot']->is($reservation->lessonSlot));

                return $entry['status'] === StudentCalendarStatus::Unavailable;
            });
    }

    public function test_teacher_week_calendar_shows_only_their_schedule_with_time_axis(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $ownReservation = $this->reservation(StudentProfile::factory()->create(['user_id' => User::factory()->create(['name' => '担当生徒'])]), $teacher, '2026-09-10 10:00:00', ReservationStatus::Approved);
        $otherReservation = $this->reservation(StudentProfile::factory()->create(['user_id' => User::factory()->create(['name' => '別講師の生徒'])]), $otherTeacher, '2026-09-10 11:00:00', ReservationStatus::Approved);

        $this->actingAs($teacher->user)
            ->get(route('staff.dashboard', ['view' => 'week', 'date' => '2026-09-10']))
            ->assertOk()
            ->assertSee('週表示')
            ->assertSee('10:00')
            ->assertSee($ownReservation->studentProfile->user->name)
            ->assertDontSee($otherReservation->studentProfile->user->name);
    }

    public function test_admin_calendar_filters_by_teacher_venue_and_reservation_status(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $admin = User::factory()->admin()->create();
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $venue = Venue::factory()->create(['name' => 'MUSAKO Aスタジオ']);
        $otherVenue = Venue::factory()->create(['name' => '別会場']);
        $matching = $this->reservation(StudentProfile::factory()->create(), $teacher, '2026-09-10 10:00:00', ReservationStatus::Pending, $venue);
        $wrongStatus = $this->reservation(StudentProfile::factory()->create(), $teacher, '2026-09-10 11:00:00', ReservationStatus::Approved, $venue);
        $wrongTeacher = $this->reservation(StudentProfile::factory()->create(), $otherTeacher, '2026-09-10 12:00:00', ReservationStatus::Pending, $otherVenue);

        $this->actingAs($admin)
            ->get(route('staff.dashboard', [
                'view' => 'day',
                'date' => '2026-09-10',
                'teacher_profile_id' => $teacher->id,
                'venue_id' => $venue->id,
                'reservation_status' => ReservationStatus::Pending->value,
            ]))
            ->assertOk()
            ->assertSee($matching->studentProfile->user->name)
            ->assertDontSee($wrongStatus->studentProfile->user->name)
            ->assertDontSee($wrongTeacher->studentProfile->user->name);
    }

    public function test_staff_reservation_detail_is_scoped_to_the_assigned_teacher(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $reservation = $this->reservation(StudentProfile::factory()->create(), $teacher, now()->addWeek()->format('Y-m-d H:i:s'), ReservationStatus::Pending);

        $this->actingAs($teacher->user)
            ->get(route('staff.reservations.show', $reservation))
            ->assertOk()
            ->assertSee('予約を確認')
            ->assertSee(route('staff.dashboard', ['view' => 'week', 'date' => $reservation->lessonSlot->starts_at->format('Y-m-d')]), escape: true)
            ->assertSee($reservation->studentProfile->user->name);

        $this->actingAs($otherTeacher->user)
            ->get(route('staff.reservations.show', $reservation))
            ->assertForbidden();
    }

    public function test_calendar_time_link_prefills_new_lesson_slot_form(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $teacher = TeacherProfile::factory()->create();

        $this->actingAs($teacher->user)
            ->get(route('staff.lesson-slots.create', ['starts_at' => '2026-09-10T10:00']))
            ->assertOk()
            ->assertSee('value="2026-09-10T10:00"', escape: false)
            ->assertSee('value="2026-09-10T11:00"', escape: false)
            ->assertSee(route('staff.dashboard', ['view' => 'week', 'date' => '2026-09-10']), escape: true);

        $slot = $this->slot($teacher, '2026-09-10 10:00:00');
        $this->get(route('staff.lesson-slots.edit', $slot))
            ->assertOk()
            ->assertSee(route('staff.dashboard', ['view' => 'week', 'date' => '2026-09-10']), escape: true);
    }

    public function test_staff_calendar_rejects_an_unknown_view_and_status_filter(): void
    {
        $teacher = TeacherProfile::factory()->create();

        $this->actingAs($teacher->user)
            ->get(route('staff.dashboard', [
                'view' => 'agenda',
                'reservation_status' => 'all-records',
            ]))
            ->assertSessionHasErrors(['view', 'reservation_status']);
    }

    /** @param array<string, mixed> $attributes */
    private function slot(TeacherProfile $teacher, string $startsAt, array $attributes = [], ?Venue $venue = null): LessonSlot
    {
        return LessonSlot::factory()
            ->for($teacher)
            ->for($venue ?? Venue::factory()->create())
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => CarbonImmutable::parse($startsAt, config('app.timezone'))->addHour(),
                ...$attributes,
            ]);
    }

    private function reservation(
        StudentProfile $student,
        TeacherProfile $teacher,
        string $startsAt,
        ReservationStatus $status,
        ?Venue $venue = null,
    ): ReservationRequest {
        return ReservationRequest::factory()
            ->for($student)
            ->for($this->slot($teacher, $startsAt, venue: $venue))
            ->create(['status' => $status]);
    }
}
