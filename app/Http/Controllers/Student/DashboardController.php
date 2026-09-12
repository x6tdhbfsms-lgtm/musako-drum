<?php

namespace App\Http\Controllers\Student;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonSlot;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $studentProfile = $user->studentProfile;

        if ($studentProfile === null) {
            return view('student.dashboard', [
                'pendingCount' => 0,
                'approvedCount' => 0,
                'nextReservation' => null,
            ]);
        }

        $reservations = $studentProfile->reservationRequests();

        return view('student.dashboard', [
            'pendingCount' => (clone $reservations)->where('status', ReservationStatus::Pending)->count(),
            'approvedCount' => (clone $reservations)->where('status', ReservationStatus::Approved)->count(),
            'nextReservation' => (clone $reservations)
                ->where('status', ReservationStatus::Approved)
                ->whereHas('lessonSlot', fn ($query) => $query->where('starts_at', '>', now()))
                ->with(['lessonSlot.teacherProfile', 'lessonSlot.venue'])
                ->orderBy(
                    LessonSlot::query()
                        ->select('starts_at')
                        ->whereColumn('lesson_slots.id', 'reservation_requests.lesson_slot_id')
                )
                ->first(),
        ]);
    }
}
