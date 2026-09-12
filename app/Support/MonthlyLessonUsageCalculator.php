<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceNoticeType;
use App\Enums\EnrollmentStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MonthlyLessonUsageCalculator
{
    public function calculate(StudentProfile $student, CarbonImmutable $month): MonthlyLessonSummary
    {
        $month = $month->setTimezone(config('app.timezone'))->startOfMonth();
        $enrollments = $student->enrollments()->orderBy('starts_on')->get();
        $contracted = $this->contractedCount($enrollments, $month);
        $reservations = $this->reservationsForMonth($student, $month);

        $completed = 0;
        $confirmed = 0;
        $pending = 0;
        $transferScheduled = 0;
        $absent = 0;
        $cancelled = 0;
        $used = 0;
        $items = collect();

        foreach ($reservations->sortBy(fn (ReservationRequest $reservation) => $this->logicalStartsAt($reservation)) as $reservation) {
            $approvedTransfer = $reservation->transferRequests
                ->first(fn ($transfer) => $transfer->status === ApplicationStatus::Approved && $transfer->resulting_reservation_request_id !== null);

            if ($approvedTransfer !== null) {
                continue;
            }

            $isTransfer = $reservation->resultingTransferRequest?->status === ApplicationStatus::Approved;
            $hasPendingTransfer = $reservation->transferRequests->contains('status', ApplicationStatus::Pending);
            if ($hasPendingTransfer || ($isTransfer && $reservation->lessonSlot->starts_at->isFuture())) {
                $transferScheduled++;
            }

            $category = 'rejected';
            $countsTowardLimit = false;

            if ($reservation->status === ReservationStatus::Cancelled) {
                $cancelled++;
                $category = 'cancelled';
            } elseif ($reservation->status === ReservationStatus::Pending) {
                $pending++;
                $used++;
                $countsTowardLimit = true;
                $category = 'pending';
            } elseif ($reservation->status === ReservationStatus::Approved) {
                $countsTowardLimit = true;
                $used++;

                if ($reservation->attendanceNotice?->type === AttendanceNoticeType::Absence) {
                    $absent++;
                    $category = 'absent';
                } elseif ($reservation->completed_at !== null || $reservation->lessonSlot->ends_at->isPast()) {
                    $completed++;
                    $category = 'completed';
                } else {
                    $confirmed++;
                    $category = 'confirmed';
                }
            }

            if ($reservation->status === ReservationStatus::Rejected) {
                continue;
            }

            $items->push([
                'reservation' => $reservation,
                'category' => $category,
                'is_transfer' => $isTransfer,
                'counts_toward_limit' => $countsTowardLimit,
                'ordinal' => null,
            ]);
        }

        $ordinal = 0;
        $items = $items->map(function (array $item) use (&$ordinal): array {
            if ($item['counts_toward_limit']) {
                $item['ordinal'] = ++$ordinal;
            }

            return $item;
        });

        return new MonthlyLessonSummary(
            month: $month,
            contracted: $contracted,
            completed: $completed,
            confirmed: $confirmed,
            pending: $pending,
            transferScheduled: $transferScheduled,
            absent: $absent,
            cancelled: $cancelled,
            used: $used,
            remaining: max(0, $contracted - $used),
            items: $items,
        );
    }

    /** @param Collection<int, LessonEnrollment> $enrollments */
    private function contractedCount(Collection $enrollments, CarbonImmutable $month): int
    {
        $monthEnd = $month->endOfMonth();
        $byId = $enrollments->keyBy('id');

        return $enrollments
            ->filter(fn (LessonEnrollment $enrollment): bool => $enrollment->status === EnrollmentStatus::Active
                && $enrollment->starts_on->lessThanOrEqualTo($monthEnd)
                && ($enrollment->ends_on === null || $enrollment->ends_on->greaterThanOrEqualTo($month)))
            ->groupBy(fn (LessonEnrollment $enrollment): int => $this->rootEnrollmentId($enrollment, $byId))
            ->map(fn (Collection $versions): int => (int) ($versions->sortByDesc('starts_on')->first()->monthly_lesson_limit ?? 0))
            ->sum();
    }

    /**
     * @param  Collection<int, LessonEnrollment>  $byId
     */
    private function rootEnrollmentId(LessonEnrollment $enrollment, Collection $byId): int
    {
        $current = $enrollment;
        $visited = [];

        while ($current->supersedes_lesson_enrollment_id !== null && ! isset($visited[$current->id])) {
            $visited[$current->id] = true;
            $parent = $byId->get($current->supersedes_lesson_enrollment_id);
            if ($parent === null) {
                break;
            }
            $current = $parent;
        }

        return $current->id;
    }

    /** @return Collection<int, ReservationRequest> */
    private function reservationsForMonth(StudentProfile $student, CarbonImmutable $month): Collection
    {
        $monthEnd = $month->endOfMonth();

        return ReservationRequest::query()
            ->whereBelongsTo($student, 'studentProfile')
            ->where(function ($query) use ($month, $monthEnd): void {
                $query->whereDate('lesson_entitlement_month', $month->toDateString())
                    ->orWhere(function ($legacy) use ($month, $monthEnd): void {
                        $legacy->whereNull('lesson_entitlement_month')
                            ->whereHas('lessonSlot', fn ($slots) => $slots->whereBetween('starts_at', [$month, $monthEnd]));
                    })
                    ->orWhereHas('resultingTransferRequest.originalReservationRequest', function ($originals) use ($month, $monthEnd): void {
                        $originals->whereDate('lesson_entitlement_month', $month->toDateString())
                            ->orWhere(function ($legacy) use ($month, $monthEnd): void {
                                $legacy->whereNull('lesson_entitlement_month')
                                    ->whereHas('lessonSlot', fn ($slots) => $slots->whereBetween('starts_at', [$month, $monthEnd]));
                            });
                    });
            })
            ->with([
                'lessonSlot.course',
                'attendanceNotice',
                'lessonEnrollment',
                'transferRequests.resultingReservationRequest.lessonSlot',
                'resultingTransferRequest.originalReservationRequest.lessonSlot',
            ])
            ->get()
            ->filter(fn (ReservationRequest $reservation): bool => $this->monthFor($reservation)->isSameMonth($month))
            ->values();
    }

    public function monthFor(ReservationRequest $reservation): CarbonImmutable
    {
        if ($reservation->lesson_entitlement_month !== null) {
            return CarbonImmutable::instance($reservation->lesson_entitlement_month)->startOfMonth();
        }

        $original = $reservation->resultingTransferRequest?->originalReservationRequest;
        if ($original?->lesson_entitlement_month !== null) {
            return CarbonImmutable::instance($original->lesson_entitlement_month)->startOfMonth();
        }

        return CarbonImmutable::instance($original?->lessonSlot?->starts_at ?? $reservation->lessonSlot->starts_at)->startOfMonth();
    }

    private function logicalStartsAt(ReservationRequest $reservation): mixed
    {
        return $reservation->resultingTransferRequest?->originalReservationRequest?->lessonSlot?->starts_at
            ?? $reservation->lessonSlot->starts_at;
    }
}
