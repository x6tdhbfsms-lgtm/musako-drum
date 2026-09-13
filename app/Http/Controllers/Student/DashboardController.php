<?php

namespace App\Http\Controllers\Student;

use App\Enums\ApplicationStatus;
use App\Enums\ReservationStatus;
use App\Enums\StudentCalendarStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonSlot;
use App\Models\PricingSetting;
use App\Models\User;
use App\Services\LessonPricingService;
use App\Support\CalendarRange;
use App\Support\MonthlyLessonUsageCalculator;
use App\Support\StudentLessonSlotState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        StudentLessonSlotState $lessonSlotState,
        MonthlyLessonUsageCalculator $monthlyLessonUsage,
        LessonPricingService $pricingService,
    ): View {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $monthValue = $validated['month'] ?? (isset($validated['date'])
            ? mb_substr($validated['date'], 0, 7)
            : now()->format('Y-m'));
        $month = CarbonImmutable::createFromFormat('!Y-m', $monthValue, config('app.timezone'))->startOfMonth();
        $calendarRange = CalendarRange::month($month);
        $selectedDate = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], config('app.timezone'))
            : ($month->isCurrentMonth() ? CarbonImmutable::today(config('app.timezone')) : $month);

        if (! $selectedDate->betweenIncluded($calendarRange->start, $calendarRange->end)) {
            $selectedDate = $month;
        }

        /** @var User $user */
        $user = $request->user();
        $studentProfile = $user->studentProfile;
        $lessonSlots = LessonSlot::query()
            ->with(['teacherProfile', 'venue', 'course'])
            ->with([
                'reservationRequests' => function ($query) use ($studentProfile): void {
                    $query->when(
                        $studentProfile === null,
                        fn ($reservations) => $reservations->whereRaw('1 = 0'),
                        fn ($reservations) => $reservations->whereBelongsTo($studentProfile, 'studentProfile')
                    )->with(['attendanceNotice', 'transferRequests', 'resultingTransferRequest']);
                },
                'requestedTransferRequests' => function ($query) use ($studentProfile): void {
                    $query->where('status', ApplicationStatus::Pending)
                        ->when(
                            $studentProfile === null,
                            fn ($requests) => $requests->whereRaw('1 = 0'),
                            fn ($requests) => $requests->whereBelongsTo($studentProfile, 'studentProfile')
                        );
                },
            ])
            ->withCount([
                'reservationRequests as approved_reservations_count' => fn ($query) => $query->where('status', ReservationStatus::Approved),
            ])
            ->whereBetween('starts_at', [$calendarRange->start, $calendarRange->end])
            ->orderBy('starts_at')
            ->get();
        $monthlySummary = $studentProfile === null ? null : $monthlyLessonUsage->calculate($studentProfile, $month);
        $calendarEntriesByDay = $lessonSlots
            ->map(function (LessonSlot $lessonSlot) use ($lessonSlotState, $studentProfile, $monthlySummary, $month): array {
                $entry = [
                    'slot' => $lessonSlot,
                    ...$lessonSlotState->resolve($lessonSlot, $studentProfile),
                ];

                if ($entry['status'] === StudentCalendarStatus::Available
                    && $lessonSlot->starts_at->isSameMonth($month)
                    && $monthlySummary?->contracted > 0
                    && $monthlySummary->remaining === 0) {
                    $entry['status'] = StudentCalendarStatus::Unavailable;
                    $entry['unavailable_reason'] = '今月の予約可能回数を使い切っています';
                }

                return $entry;
            })
            ->groupBy(fn (array $entry): string => $entry['slot']->starts_at->format('Y-m-d'));

        $reservations = $studentProfile?->reservationRequests();
        $currentEnrollments = $studentProfile?->enrollments()
            ->activeOn(now())
            ->with(['course', 'teacherProfile', 'venue'])
            ->orderBy('starts_on')
            ->get() ?? collect();
        $pricingQuotes = $currentEnrollments->mapWithKeys(
            fn ($enrollment): array => [$enrollment->id => $pricingService->forEnrollment($enrollment, today())],
        );

        return view('student.dashboard', [
            'pendingCount' => $reservations === null ? 0 : (clone $reservations)->where('status', ReservationStatus::Pending)->count(),
            'approvedCount' => $reservations === null ? 0 : (clone $reservations)->where('status', ReservationStatus::Approved)->count(),
            'nextReservation' => $reservations === null ? null : (clone $reservations)
                ->where('status', ReservationStatus::Approved)
                ->whereHas('lessonSlot', fn ($query) => $query->where('starts_at', '>', now()))
                ->with(['lessonSlot.teacherProfile', 'lessonSlot.venue'])
                ->orderBy(
                    LessonSlot::query()
                        ->select('starts_at')
                        ->whereColumn('lesson_slots.id', 'reservation_requests.lesson_slot_id')
                )
                ->first(),
            'month' => $month,
            'calendarRange' => $calendarRange,
            'calendarDays' => $calendarRange->days(),
            'calendarEntriesByDay' => $calendarEntriesByDay,
            'selectedDate' => $selectedDate,
            'monthlySummary' => $monthlySummary,
            'currentEnrollments' => $currentEnrollments,
            'pricingQuotes' => $pricingQuotes,
            'pricingSetting' => PricingSetting::query()->effectiveOn(today())->first(),
        ]);
    }
}
