<?php

namespace App\Http\Controllers\Student;

use App\Enums\LessonSlotAudience;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonSlot;
use App\Models\User;
use App\Services\TeacherBookingAvailability;
use App\Support\CalendarRange;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AvailableLessonSlotController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, MonthlyLessonUsageCalculator $monthlyLessonUsage, TeacherBookingAvailability $availability): View
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'month' => ['nullable', 'date_format:Y-m']]);
        $focus = CarbonImmutable::parse($validated['date'] ?? (isset($validated['month']) ? $validated['month'].'-01' : now()->toDateString()));
        $range = CalendarRange::forView($focus, 'week');
        $month = $focus->startOfMonth();

        /** @var User $user */
        $user = $request->user();
        $lessonSlots = LessonSlot::query()
            ->with(['teacherProfile', 'venue', 'course'])
            ->withCount(['reservationRequests as approved_reservations_count' => fn ($query) => $query->where('status', ReservationStatus::Approved)])
            ->withCount(['trialLessonRequests as approved_trial_requests_count' => fn ($query) => $query->where('status', TrialLessonStatus::Approved)])
            ->where('status', LessonSlotStatus::Open)
            ->whereIn('booking_audience', [LessonSlotAudience::Regular, LessonSlotAudience::Both])
            ->where('starts_at', '>', now())
            ->where('starts_at', '>=', $range->start)
            ->where('starts_at', '<=', $range->end)
            ->whereTime('starts_at', '>=', '12:00:00')
            ->whereTime('ends_at', '<=', '21:00:00')
            ->orderBy('starts_at')
            ->get();

        $lessonSlots = $availability->availableSlots($lessonSlots);

        $requestedSlotIds = $user->studentProfile === null
            ? collect()
            : $user->studentProfile->reservationRequests()
                ->whereIn('lesson_slot_id', $lessonSlots->modelKeys())
                ->pluck('lesson_slot_id');

        return view('student.lesson-slots.index', [
            'month' => $month,
            'range' => $range,
            'calendarDays' => $range->days(),
            'monthlySummaries' => collect($range->days()->toArray())->map(fn ($day) => $day->format('Y-m'))->unique()->mapWithKeys(fn ($key) => [$key => $user->studentProfile === null ? null : $monthlyLessonUsage->calculate($user->studentProfile, CarbonImmutable::parse($key.'-01'))]),
            'lessonSlotsByDay' => $lessonSlots->groupBy(fn (LessonSlot $slot) => $slot->starts_at->format('Y-m-d')),
            'requestedSlotIds' => $requestedSlotIds,
            'monthlySummary' => $user->studentProfile === null
                ? null
                : $monthlyLessonUsage->calculate($user->studentProfile, $month),
        ]);
    }
}
