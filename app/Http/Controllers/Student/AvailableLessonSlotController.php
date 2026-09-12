<?php

namespace App\Http\Controllers\Student;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonSlot;
use App\Models\User;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AvailableLessonSlotController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, MonthlyLessonUsageCalculator $monthlyLessonUsage): View
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'))->startOfMonth();

        /** @var User $user */
        $user = $request->user();
        $lessonSlots = LessonSlot::query()
            ->with(['teacherProfile', 'venue', 'course'])
            ->withCount(['reservationRequests as approved_reservations_count' => fn ($query) => $query->where('status', ReservationStatus::Approved)])
            ->where('status', LessonSlotStatus::Open)
            ->where('starts_at', '>', now())
            ->where('starts_at', '>=', $month)
            ->where('starts_at', '<', $month->addMonth())
            ->orderBy('starts_at')
            ->get();

        $requestedSlotIds = $user->studentProfile === null
            ? collect()
            : $user->studentProfile->reservationRequests()
                ->whereIn('lesson_slot_id', $lessonSlots->modelKeys())
                ->pluck('lesson_slot_id');

        return view('student.lesson-slots.index', [
            'month' => $month,
            'calendarDays' => CarbonPeriod::create($month->startOfWeek(CarbonImmutable::SUNDAY), $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY)),
            'lessonSlotsByDay' => $lessonSlots->groupBy(fn (LessonSlot $slot) => $slot->starts_at->format('Y-m-d')),
            'requestedSlotIds' => $requestedSlotIds,
            'monthlySummary' => $user->studentProfile === null
                ? null
                : $monthlyLessonUsage->calculate($user->studentProfile, $month),
        ]);
    }
}
