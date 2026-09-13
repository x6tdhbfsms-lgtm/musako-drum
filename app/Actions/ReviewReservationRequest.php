<?php

namespace App\Actions;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\LessonPricingService;
use App\Services\MusakoNotificationService;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewReservationRequest
{
    public function __construct(
        private readonly MonthlyLessonUsageCalculator $monthlyLessonUsage,
        private readonly LessonPricingService $lessonPricing,
        private readonly MusakoNotificationService $notifications,
    ) {}

    public function handle(
        ReservationRequest $reservationRequest,
        User $reviewer,
        ReservationStatus $decision,
        ?string $staffNote,
        bool $overrideMonthlyLimit = false,
        ?string $overrideReason = null,
    ): ReservationRequest {
        $reviewedReservation = DB::transaction(function () use ($reservationRequest, $reviewer, $decision, $staffNote, $overrideMonthlyLimit, $overrideReason): ReservationRequest {
            $lockedReservation = ReservationRequest::query()->lockForUpdate()->findOrFail($reservationRequest->id);
            $lockedStudent = StudentProfile::query()->lockForUpdate()->findOrFail($lockedReservation->student_profile_id);
            $lockedSlot = LessonSlot::query()->lockForUpdate()->findOrFail($lockedReservation->lesson_slot_id);

            if ($lockedReservation->status !== ReservationStatus::Pending) {
                throw ValidationException::withMessages(['reservation' => 'この申請はすでに処理されています。']);
            }

            if ($decision === ReservationStatus::Approved) {
                if ($lockedSlot->status !== LessonSlotStatus::Open || ! $lockedSlot->starts_at->isFuture()) {
                    throw ValidationException::withMessages(['reservation' => '受付中の未来の枠だけ承認できます。']);
                }

                $approvedCount = $lockedSlot->reservationRequests()->where('status', ReservationStatus::Approved)->count();
                if ($approvedCount >= $lockedSlot->capacity) {
                    throw ValidationException::withMessages(['reservation' => '定員に達しているため承認できません。']);
                }

                $enrollment = $lockedReservation->lessonEnrollment;
                if ($enrollment?->student_profile_id === $lockedStudent->id && $enrollment->monthly_lesson_limit !== null) {
                    $entitlementMonth = CarbonImmutable::instance(
                        $lockedReservation->lesson_entitlement_month ?? $lockedSlot->starts_at,
                    )->startOfMonth();
                    $summary = $this->monthlyLessonUsage->calculate($lockedStudent, $entitlementMonth);
                    if ($summary->used > $summary->contracted && ! $overrideMonthlyLimit) {
                        throw ValidationException::withMessages([
                            'reservation' => '月間の契約回数を超えるため承認できません。上限超過として承認する場合は理由を入力してください。',
                        ]);
                    }

                    if ($summary->used > $summary->contracted) {
                        $lockedReservation->forceFill([
                            'monthly_limit_overridden_at' => now(),
                            'monthly_limit_overridden_by_user_id' => $reviewer->id,
                            'monthly_limit_override_reason' => $overrideReason,
                        ]);
                    }
                }

                if ($enrollment?->student_profile_id === $lockedStudent->id) {
                    $quote = $this->lessonPricing->forEnrollment($enrollment, $lockedSlot->starts_at);
                    $lockedReservation->forceFill([
                        'studio_fee_amount' => $quote->studioFeePerLesson,
                        'studio_fee_priced_on' => $lockedSlot->starts_at->toDateString(),
                    ]);
                }
            }

            $lockedReservation->update([
                'status' => $decision,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'staff_note' => $staffNote,
            ]);

            return $lockedReservation->refresh();
        }, 3);

        $this->notifications->reservationReviewed($reviewedReservation, $decision);

        return $reviewedReservation;
    }
}
