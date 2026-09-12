<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ReservationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class ReservationDetailController extends Controller
{
    public function __invoke(ReservationRequest $reservationRequest): View
    {
        Gate::authorize('view', $reservationRequest);
        $reservationRequest->load([
            'lessonSlot.teacherProfile.user',
            'lessonSlot.venue',
            'lessonSlot.course',
            'attendanceNotice',
            'transferRequests.requestedLessonSlot',
            'resultingTransferRequest.originalReservationRequest.lessonSlot',
        ]);

        return view('student.reservations.show', compact('reservationRequest'));
    }
}
