<?php

namespace App\Http\Controllers\Student;

use App\Actions\UpsertAttendanceNotice;
use App\Enums\AttendanceNoticeType;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertAttendanceNoticeRequest;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AttendanceNoticeController extends Controller
{
    public function index(): View
    {
        /** @var User $user */
        $user = request()->user();
        $studentProfile = $user->studentProfile;
        $reservations = ReservationRequest::query()
            ->with(['lessonSlot.teacherProfile', 'lessonSlot.venue', 'attendanceNotice'])
            ->when(
                $studentProfile === null,
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->whereBelongsTo($studentProfile, 'studentProfile')
            )
            ->where('status', ReservationStatus::Approved)
            ->whereHas('lessonSlot', fn ($query) => $query->where('ends_at', '>', now()))
            ->orderBy(LessonSlot::query()->select('starts_at')->whereColumn('lesson_slots.id', 'reservation_requests.lesson_slot_id'))
            ->get();

        return view('student.attendance-notices.index', compact('reservations'));
    }

    public function store(
        UpsertAttendanceNoticeRequest $request,
        ReservationRequest $reservationRequest,
        UpsertAttendanceNotice $upsertAttendanceNotice,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $upsertAttendanceNotice->handle(
            $user->studentProfile,
            $reservationRequest,
            AttendanceNoticeType::from($request->validated('type')),
            $request->validated('late_minutes'),
            $request->validated('expected_arrival_time'),
            $request->validated('notes'),
        );

        return redirect()->route('student.attendance-notices.index')->with('success', 'お休み・遅刻連絡を保存しました。');
    }
}
