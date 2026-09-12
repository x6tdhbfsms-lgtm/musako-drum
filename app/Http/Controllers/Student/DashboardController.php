<?php

namespace App\Http\Controllers\Student;

use App\Enums\ApplicationStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonSlot;
use App\Models\User;
use App\Support\CalendarRange;
use App\Support\StudentLessonSlotState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, StudentLessonSlotState $lessonSlotState): View
    {
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
        $calendarEntriesByDay = $lessonSlots
            ->map(fn (LessonSlot $lessonSlot): array => [
                'slot' => $lessonSlot,
                ...$lessonSlotState->resolve($lessonSlot, $studentProfile),
            ])
            ->groupBy(fn (array $entry): string => $entry['slot']->starts_at->format('Y-m-d'));

        $reservations = $studentProfile?->reservationRequests();

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
        ]);
    }
}
