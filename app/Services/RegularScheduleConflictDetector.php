<?php

namespace App\Services;

use App\Enums\RegularScheduleOccurrenceStatus;
use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;
use App\Models\RegularScheduleOccurrence;

class RegularScheduleConflictDetector
{
    public function __construct(private readonly LessonSlotCapacityService $capacity) {}

    /** @return list<string> */
    public function detect(RegularScheduleOccurrence $occurrence): array
    {
        $reasons = [];
        $overlap = fn ($query) => $query
            ->where('starts_at', '<', $occurrence->ends_at)
            ->where('ends_at', '>', $occurrence->starts_at);

        $overlappingSlots = LessonSlot::query()
            ->where(fn ($query) => $query
                ->where('teacher_profile_id', $occurrence->teacher_profile_id)
                ->orWhere(fn ($venues) => $venues->whereNotNull('venue_id')->where('venue_id', $occurrence->venue_id)))
            ->where($overlap)
            ->withCount([
                'reservationRequests as active_reservations_count' => fn ($query) => $query->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Approved]),
                'trialLessonRequests as active_trials_count' => fn ($query) => $query->whereIn('status', [TrialLessonStatus::Pending, TrialLessonStatus::Approved]),
            ])->get();

        foreach ($overlappingSlots as $slot) {
            $sameReusableSlot = $slot->teacher_profile_id === $occurrence->teacher_profile_id
                && $slot->venue_id === $occurrence->venue_id
                && $slot->course_id === $occurrence->course_id
                && $slot->starts_at->equalTo($occurrence->starts_at)
                && $slot->ends_at->equalTo($occurrence->ends_at);

            if ($sameReusableSlot) {
                if ($this->capacity->occupiedSeats($slot) >= $slot->capacity) {
                    $reasons[] = '既存レッスン枠が定員に達しています。';
                }

                continue;
            }

            if ($slot->teacher_profile_id === $occurrence->teacher_profile_id) {
                $reasons[] = '担当講師の同時間帯に別のレッスン枠があります。';
            }
            if ($occurrence->venue_id !== null && $slot->venue_id === $occurrence->venue_id) {
                $reasons[] = '会場の同時間帯に別のレッスン枠があります。';
            }
        }

        $studentConflict = LessonSlot::query()
            ->where($overlap)
            ->whereHas('reservationRequests', fn ($query) => $query
                ->where('student_profile_id', $occurrence->student_profile_id)
                ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Approved]))
            ->exists();
        if ($studentConflict) {
            $reasons[] = '生徒本人に同時間帯の予約があります。';
        }

        $draftConflict = RegularScheduleOccurrence::query()
            ->whereKeyNot($occurrence->id)
            ->whereIn('status', [
                RegularScheduleOccurrenceStatus::Draft,
                RegularScheduleOccurrenceStatus::Conflict,
                RegularScheduleOccurrenceStatus::Confirmed,
            ])
            ->where('starts_at', '<', $occurrence->ends_at)
            ->where('ends_at', '>', $occurrence->starts_at)
            ->where(fn ($query) => $query
                ->where('student_profile_id', $occurrence->student_profile_id)
                ->orWhere('teacher_profile_id', $occurrence->teacher_profile_id)
                ->orWhere(fn ($venues) => $venues->whereNotNull('venue_id')->where('venue_id', $occurrence->venue_id)))
            ->exists();
        if ($draftConflict) {
            $reasons[] = '別のレギュラー予定と時間が重複しています。';
        }

        return array_values(array_unique($reasons));
    }
}
