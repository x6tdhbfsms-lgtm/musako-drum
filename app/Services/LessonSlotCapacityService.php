<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;

class LessonSlotCapacityService
{
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
        return max(0, $lessonSlot->capacity - $this->approvedReservationCount($lessonSlot) - $this->activeTrialCount($lessonSlot));
    }
}
