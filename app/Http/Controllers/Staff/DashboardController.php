<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApplicationStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AttendanceNotice;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\ReservationRequest;
use App\Models\TransferRequest;
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
        $todayNotices = AttendanceNotice::query()
            ->with(['reservationRequest.studentProfile.user', 'reservationRequest.lessonSlot'])
            ->whereHas('reservationRequest.lessonSlot', function ($query) use ($teacherProfile, $user): void {
                $query->whereDate('starts_at', today()->toDateString())
                    ->when(
                        $user->role === UserRole::Teacher,
                        fn ($slots) => $teacherProfile === null ? $slots->whereRaw('1 = 0') : $slots->whereBelongsTo($teacherProfile, 'teacherProfile')
                    );
            })
            ->get()
            ->sortBy(fn (AttendanceNotice $notice) => $notice->reservationRequest->lessonSlot->starts_at);
        $pendingTransferCount = TransferRequest::query()
            ->where('status', ApplicationStatus::Pending)
            ->when(
                $user->role === UserRole::Teacher,
                fn ($query) => $teacherProfile === null
                    ? $query->whereRaw('1 = 0')
                    : $query->where(function ($requests) use ($teacherProfile): void {
                        $requests->whereHas('originalReservationRequest.lessonSlot', fn ($slots) => $slots->whereBelongsTo($teacherProfile, 'teacherProfile'))
                            ->orWhereHas('requestedLessonSlot', fn ($slots) => $slots->whereBelongsTo($teacherProfile, 'teacherProfile'));
                    })
            )
            ->count();
        $pendingMembershipCount = MembershipStatusRequest::query()
            ->where('status', ApplicationStatus::Pending)
            ->count();

        return view('staff.dashboard', [
            'pendingCount' => (clone $reservations)->where('status', ReservationStatus::Pending)->count(),
            'upcomingSlotCount' => (clone $slots)->where('starts_at', '>', now())->count(),
            'pendingReservations' => (clone $reservations)
                ->where('status', ReservationStatus::Pending)
                ->with(['studentProfile.user', 'lessonSlot'])
                ->oldest('requested_at')
                ->limit(5)
                ->get(),
            'todayNotices' => $todayNotices,
            'pendingTransferCount' => $pendingTransferCount,
            'pendingMembershipCount' => $pendingMembershipCount,
        ]);
    }
}
