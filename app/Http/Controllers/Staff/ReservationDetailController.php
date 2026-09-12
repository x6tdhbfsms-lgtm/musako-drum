<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ReservationRequest;
use App\Support\MonthlyLessonUsageCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class ReservationDetailController extends Controller
{
    public function __invoke(ReservationRequest $reservationRequest, MonthlyLessonUsageCalculator $monthlyLessonUsage): View
    {
        Gate::authorize('view', $reservationRequest);
        $reservationRequest->load([
            'studentProfile.user',
            'lessonEnrollment.course',
            'lessonEnrollment.teacherProfile',
            'lessonEnrollment.venue',
            'lessonSlot.teacherProfile.user',
            'lessonSlot.venue',
            'lessonSlot.course',
            'attendanceNotice',
            'transferRequests.requestedLessonSlot',
            'resultingTransferRequest.originalReservationRequest.lessonSlot',
            'reviewer',
        ]);

        $monthlySummary = $monthlyLessonUsage->calculate(
            $reservationRequest->studentProfile,
            $monthlyLessonUsage->monthFor($reservationRequest),
        );
        $monthlyLessonItem = $monthlySummary->items->first(
            fn (array $item): bool => $item['reservation']->is($reservationRequest),
        );

        return view('staff.reservations.show', compact('reservationRequest', 'monthlySummary', 'monthlyLessonItem'));
    }
}
