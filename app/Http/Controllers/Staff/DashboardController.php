<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
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
        $teacherProfile = $user->teacherProfile;
        $reservations = ReservationRequest::query()
            ->when(
                $user->role === UserRole::Teacher,
                fn ($query) => $teacherProfile === null
                    ? $query->whereRaw('1 = 0')
                    : $query->whereHas('lessonSlot', fn ($slots) => $slots->whereBelongsTo($teacherProfile, 'teacherProfile'))
            );
        $slots = LessonSlot::query()
            ->when(
                $user->role === UserRole::Teacher,
                fn ($query) => $teacherProfile === null ? $query->whereRaw('1 = 0') : $query->whereBelongsTo($teacherProfile, 'teacherProfile')
            );

        return view('staff.dashboard', [
            'pendingCount' => (clone $reservations)->where('status', ReservationStatus::Pending)->count(),
            'upcomingSlotCount' => (clone $slots)->where('starts_at', '>', now())->count(),
            'pendingReservations' => (clone $reservations)
                ->where('status', ReservationStatus::Pending)
                ->with(['studentProfile.user', 'lessonSlot'])
                ->oldest('requested_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
