<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;

class LessonSlotCapacityService
{
    /**
     * Count seats occupied by approved reservations and approved trial lessons.
     * Reservations created from regular schedules and approved transfers are
     * represented by the same reservation table and are therefore counted once.
     */
    public function occupiedSeats(LessonSlot $lessonSlot): int
    {
        return $lessonSlot->reservationRequests()
            ->where('status', ReservationStatus::Approved)
            ->count()
            + $lessonSlot->trialLessonRequests()
                ->where('status', TrialLessonStatus::Approved)
                ->count();
    }

    public function hasCapacity(LessonSlot $lessonSlot): bool
    {
        return $this->occupiedSeats($lessonSlot) < (int) $lessonSlot->capacity;
    }

    public function approvedReservationCount(LessonSlot $lessonSlot): int
    {
        return $lessonSlot->reservationRequests()
            ->where('status', ReservationStatus::Approved)
            ->count();
    }

    public function activeTrialCount(LessonSlot $lessonSlot, ?int $exceptTrialRequestId = null): int
    {
        return $lessonSlot->trialLessonRequests()
            ->whereIn('status', [TrialLessonStatus::Pending, TrialLessonStatus::Approved])
            ->when($exceptTrialRequestId !== null, fn ($query) => $query->whereKeyNot($exceptTrialRequestId))
            ->count();
    }

    public function confirmedTrialCount(LessonSlot $lessonSlot, ?int $exceptTrialRequestId = null): int
    {
        return $lessonSlot->trialLessonRequests()
            ->where('status', TrialLessonStatus::Approved)
            ->when($exceptTrialRequestId !== null, fn ($query) => $query->whereKeyNot($exceptTrialRequestId))
            ->count();
    }

    public function availableForTrialApplication(LessonSlot $lessonSlot): int
    {
        return max(0, (int) $lessonSlot->capacity - $this->approvedReservationCount($lessonSlot) - $this->activeTrialCount($lessonSlot));
    }
}
