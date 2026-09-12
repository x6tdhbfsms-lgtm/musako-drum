<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApplicationStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffCalendarRequest;
use App\Models\AttendanceNotice;
use App\Models\ContractChangeRequest;
use App\Models\Inquiry;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\ReservationRequest;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Venue;
use App\Support\CalendarRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(StaffCalendarRequest $request): View
    {
        $validated = $request->validated();
        $calendarView = $validated['view'] ?? 'month';
        $focus = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $validated['date'] ?? now()->format('Y-m-d'),
            config('app.timezone'),
        );
        $calendarRange = CalendarRange::forView($focus, $calendarView);
        $reservationStatus = isset($validated['reservation_status'])
            ? ReservationStatus::from($validated['reservation_status'])
            : null;

        /** @var User $user */
        $user = $request->user();
        $teacherProfile = $user->teacherProfile;
        $teacherFilterId = $user->role === UserRole::Teacher
            ? $teacherProfile?->id
            : ($validated['teacher_profile_id'] ?? null);
        $venueFilterId = $validated['venue_id'] ?? null;

        $calendarSlots = LessonSlot::query()
            ->with(['teacherProfile.user', 'venue', 'course'])
            ->with(['reservationRequests' => function ($query) use ($reservationStatus): void {
                $query->when($reservationStatus !== null, fn ($reservations) => $reservations->where('status', $reservationStatus))
                    ->with(['studentProfile.user', 'attendanceNotice', 'transferRequests', 'resultingTransferRequest'])
                    ->orderBy('requested_at');
            }])
            ->withCount([
                'reservationRequests as approved_reservations_count' => fn ($query) => $query->where('status', ReservationStatus::Approved),
            ])
            ->when($teacherFilterId === null && $user->role === UserRole::Teacher, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($teacherFilterId !== null, fn ($query) => $query->where('teacher_profile_id', $teacherFilterId))
            ->when($venueFilterId !== null, fn ($query) => $query->where('venue_id', $venueFilterId))
            ->when(
                $reservationStatus !== null,
                fn ($query) => $query->whereHas('reservationRequests', fn ($reservations) => $reservations->where('status', $reservationStatus))
            )
            ->whereBetween('starts_at', [$calendarRange->start, $calendarRange->end])
            ->orderBy('starts_at')
            ->get();
        $calendarSlotsByDay = $calendarSlots->groupBy(fn (LessonSlot $slot): string => $slot->starts_at->format('Y-m-d'));
        $firstTimelineHour = min(8, (int) ($calendarSlots->min(fn (LessonSlot $slot): int => $slot->starts_at->hour) ?? 8));
        $lastTimelineHour = max(22, (int) ($calendarSlots->max(fn (LessonSlot $slot): int => $slot->starts_at->hour) ?? 22));

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

        return view('staff.dashboard', [
            'pendingCount' => (clone $reservations)->where('status', ReservationStatus::Pending)->count(),
            'upcomingSlotCount' => (clone $slots)->where('starts_at', '>', now())->count(),
            'todayNotices' => $todayNotices,
            'pendingTransferCount' => $pendingTransferCount,
            'pendingMembershipCount' => MembershipStatusRequest::query()->where('status', ApplicationStatus::Pending)->count(),
            'pendingProcedureCount' => ContractChangeRequest::query()->where('status', ApplicationStatus::Pending)->count()
                + PersonalInformationChangeRequest::query()->where('status', ApplicationStatus::Pending)->count()
                + PaymentMethodChangeRequest::query()->where('status', ApplicationStatus::Pending)->count(),
            'openInquiryCount' => Inquiry::query()->whereIn('status', ['open', 'in_progress'])->count(),
            'calendarView' => $calendarView,
            'calendarRange' => $calendarRange,
            'calendarDays' => $calendarRange->days(),
            'calendarSlotsByDay' => $calendarSlotsByDay,
            'timelineHours' => range($firstTimelineHour, $lastTimelineHour),
            'teachers' => TeacherProfile::query()->orderBy('display_name')->get(),
            'venues' => Venue::query()->orderBy('name')->get(),
            'teacherFilterId' => $teacherFilterId,
            'venueFilterId' => $venueFilterId,
            'reservationStatus' => $reservationStatus,
        ]);
    }
}
