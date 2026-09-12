<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceNoticeType;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Enums\StudentCalendarStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;

class StudentLessonSlotState
{
    /** @return array{status: StudentCalendarStatus, reservation: ReservationRequest|null} */
    public function resolve(LessonSlot $lessonSlot, ?StudentProfile $studentProfile): array
    {
        /** @var ReservationRequest|null $reservation */
        $reservation = $lessonSlot->reservationRequests
            ->where('student_profile_id', $studentProfile?->id)
            ->sortByDesc('id')
            ->first();

        if ($reservation?->attendanceNotice?->type === AttendanceNoticeType::Absence) {
            return ['status' => StudentCalendarStatus::Absence, 'reservation' => $reservation];
        }

        if ($reservation?->attendanceNotice?->type === AttendanceNoticeType::Late) {
            return ['status' => StudentCalendarStatus::Late, 'reservation' => $reservation];
        }

        if ($lessonSlot->requestedTransferRequests->contains('status', ApplicationStatus::Pending)
            || $reservation?->transferRequests->contains('status', ApplicationStatus::Pending)) {
            return ['status' => StudentCalendarStatus::TransferPending, 'reservation' => $reservation];
        }

        if ($reservation?->resultingTransferRequest?->status === ApplicationStatus::Approved
            || $reservation?->transferRequests->contains('status', ApplicationStatus::Approved)) {
            return ['status' => StudentCalendarStatus::TransferApproved, 'reservation' => $reservation];
        }

        if ($reservation?->status === ReservationStatus::Pending) {
            return ['status' => StudentCalendarStatus::ReservationPending, 'reservation' => $reservation];
        }

        if ($reservation?->status === ReservationStatus::Approved) {
            return ['status' => StudentCalendarStatus::ReservationApproved, 'reservation' => $reservation];
        }

        if ($reservation !== null) {
            return ['status' => StudentCalendarStatus::Unavailable, 'reservation' => $reservation];
        }

        if ($lessonSlot->status !== LessonSlotStatus::Open || ! $lessonSlot->starts_at->isFuture() || $studentProfile === null) {
            return ['status' => StudentCalendarStatus::Unavailable, 'reservation' => $reservation];
        }

        if ($lessonSlot->approved_reservations_count >= $lessonSlot->capacity) {
            return ['status' => StudentCalendarStatus::Full, 'reservation' => $reservation];
        }

        return ['status' => StudentCalendarStatus::Available, 'reservation' => null];
    }
}
