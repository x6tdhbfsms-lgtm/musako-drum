<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApplicationStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\MonthlyInvoiceStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffCalendarRequest;
use App\Models\ContractChangeRequest;
use App\Models\Inquiry;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\MonthlyInvoice;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\RegularScheduleBatch;
use App\Models\RegularScheduleOccurrence;
use App\Models\ReservationRequest;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Venue;
use App\Services\LessonOperatingHours;
use App\Support\CalendarRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(StaffCalendarRequest $request): View
    {
        $validated = $request->validated();
        $calendarView = $validated['view'] ?? 'week';
        $focus = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $validated['date'] ?? now()->format('Y-m-d'),
            config('app.timezone'),
        );
        $calendarRange = $calendarView === 'week'
            ? CalendarRange::weekStartingOn($focus)
            : CalendarRange::forView($focus, $calendarView);
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
        $regularBlocks = RegularScheduleOccurrence::query()
            ->with(['studentProfile.user', 'teacherProfile', 'venue'])
            ->whereIn('status', ['draft', 'conflict'])
            ->whereBetween('starts_at', [$calendarRange->start, $calendarRange->end])
            ->when($reservationStatus !== null, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($user->role === UserRole::Teacher && $teacherFilterId === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($teacherFilterId !== null, fn ($query) => $query->where('teacher_profile_id', $teacherFilterId))
            ->when($venueFilterId !== null, fn ($query) => $query->where('venue_id', $venueFilterId))
            ->get();
        $calendarSlots = $calendarSlots->toBase()->concat($regularBlocks)->sortBy('starts_at')->values();
        $calendarSlotsByDay = $calendarSlots->groupBy(fn ($slot): string => $slot->starts_at->format('Y-m-d'));
        [$openingHour, $closingHour] = $calendarView === 'day'
            ? LessonOperatingHours::hours($calendarRange->focus) : [10, 21];
        $firstTimelineHour = min($openingHour, (int) ($calendarSlots->min(fn ($slot): int => $slot->starts_at->hour) ?? $openingHour));
        $lastTimelineHour = max($closingHour - 1, (int) ($calendarSlots->max(fn ($slot): int => $slot->starts_at->hour) ?? ($closingHour - 1)));

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
        $todayLessonCount = ReservationRequest::query()
            ->where('status', ReservationStatus::Approved)
            ->whereHas('lessonSlot', function ($query) use ($teacherProfile, $user): void {
                $query->whereBetween('starts_at', [today()->startOfDay(), today()->endOfDay()])
                    ->when(
                        $user->role === UserRole::Teacher,
                        fn ($slots) => $teacherProfile === null ? $slots->whereRaw('1 = 0') : $slots->whereBelongsTo($teacherProfile, 'teacherProfile')
                    );
            })
            ->count();
        $currentBillingMonth = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $deferredTransfers = TransferRequest::query()
            ->where('status', ApplicationStatus::Approved)
            ->whereNull('requested_lesson_slot_id')
            ->whereNull('resulting_reservation_request_id')
            ->when(
                $user->role === UserRole::Teacher,
                fn ($query) => $teacherProfile === null
                    ? $query->whereRaw('1 = 0')
                    : $query->whereHas('originalReservationRequest.lessonSlot', fn ($slots) => $slots->whereBelongsTo($teacherProfile, 'teacherProfile'))
            );
        $deferredTransferCount = (clone $deferredTransfers)->count();
        $overdueDeferredTransferCount = (clone $deferredTransfers)
            ->whereHas('originalReservationRequest', function ($reservations) use ($currentBillingMonth): void {
                $reservations->where(function ($lesson) use ($currentBillingMonth): void {
                    $lesson->whereDate('lesson_entitlement_month', '<', $currentBillingMonth->toDateString())
                        ->orWhere(function ($lessonWithoutEntitlement) use ($currentBillingMonth): void {
                            $lessonWithoutEntitlement
                                ->whereNull('lesson_entitlement_month')
                                ->whereHas('lessonSlot', fn ($slots) => $slots->where('starts_at', '<', $currentBillingMonth));
                        });
                });
            })
            ->count();
        $nextRegularMonth = CarbonImmutable::now(config('app.timezone'))->addMonth()->startOfMonth();
        $regularScope = RegularScheduleOccurrence::query()
            ->whereDate('lesson_entitlement_month', $nextRegularMonth)
            ->when($user->role === UserRole::Teacher, fn ($query) => $query->where('teacher_profile_id', $teacherProfile?->id ?? 0));
        $regularBatchScope = RegularScheduleBatch::query()
            ->whereDate('entitlement_month', $nextRegularMonth)
            ->when($user->role === UserRole::Teacher, fn ($query) => $query->whereHas('lessonEnrollment', fn ($enrollments) => $enrollments->where('teacher_profile_id', $teacherProfile?->id ?? 0)));
        $billingInvoices = $user->role === UserRole::Admin
            ? MonthlyInvoice::query()->whereDate('billing_month', $currentBillingMonth)->get()
            : collect();

        return view('staff.dashboard', [
            'pendingTransferCount' => $pendingTransferCount,
            'todayLessonCount' => $todayLessonCount,
            'deferredTransferCount' => $deferredTransferCount,
            'overdueDeferredTransferCount' => $overdueDeferredTransferCount,
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
            'nextRegularMonth' => $nextRegularMonth,
            'nextRegularDraftCount' => (clone $regularScope)->where('status', 'draft')->count(),
            'nextRegularConflictCount' => (clone $regularScope)->where('status', 'conflict')->count(),
            'nextRegularConfirmedCount' => (clone $regularScope)->where('status', 'confirmed')->count(),
            'nextRegularWarningCount' => (clone $regularBatchScope)->whereNotNull('warning')->count(),
            'billingSummary' => $user->role === UserRole::Admin ? [
                'draft' => $billingInvoices->where('status', MonthlyInvoiceStatus::Draft)->count(),
                'unpaid' => $billingInvoices->where('status', MonthlyInvoiceStatus::Confirmed)->where('payment_status', InvoicePaymentStatus::Unpaid)->count(),
                'partial' => $billingInvoices->where('payment_status', InvoicePaymentStatus::PartiallyPaid)->count(),
                'overdue' => $billingInvoices->filter->is_overdue->count(),
                'outstanding' => $billingInvoices->where('status', MonthlyInvoiceStatus::Confirmed)->sum(fn ($invoice) => $invoice->remaining_amount),
            ] : null,
        ]);
    }
}
