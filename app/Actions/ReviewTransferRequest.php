<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\LessonPricingService;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewTransferRequest
{
    public function __construct(
        private readonly LessonPricingService $lessonPricing,
        private readonly MusakoNotificationService $notifications,
    ) {}

    public function handle(
        TransferRequest $transferRequest,
        User $reviewer,
        ApplicationStatus $decision,
        ?string $staffNote,
    ): TransferRequest {
        $reviewedTransfer = DB::transaction(function () use ($transferRequest, $reviewer, $decision, $staffNote): TransferRequest {
            $lockedTransfer = TransferRequest::query()->lockForUpdate()->findOrFail($transferRequest->id);

            if ($lockedTransfer->status !== ApplicationStatus::Pending) {
                throw ValidationException::withMessages(['transfer_request' => 'この振替申請はすでに処理されています。']);
            }

            if ($decision === ApplicationStatus::Rejected) {
                $lockedTransfer->update([
                    'status' => ApplicationStatus::Rejected,
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => now(),
                    'staff_note' => $staffNote,
                ]);

                return $lockedTransfer->refresh();
            }

            $originalReservation = ReservationRequest::query()->lockForUpdate()->findOrFail($lockedTransfer->original_reservation_request_id);
            $lockedSlots = LessonSlot::query()
                ->whereKey([$originalReservation->lesson_slot_id, $lockedTransfer->requested_lesson_slot_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $requestedSlot = $lockedSlots->firstWhere('id', $lockedTransfer->requested_lesson_slot_id);

            if ($originalReservation->status !== ReservationStatus::Approved) {
                throw ValidationException::withMessages(['transfer_request' => '元の予約が承認済みではないため振替できません。']);
            }

            if ($requestedSlot === null || $requestedSlot->status !== LessonSlotStatus::Open || ! $requestedSlot->starts_at->isFuture()) {
                throw ValidationException::withMessages(['transfer_request' => '振替先の枠は現在予約できません。']);
            }

            if ($requestedSlot->reservationRequests()->where('status', ReservationStatus::Approved)->count() >= $requestedSlot->capacity) {
                throw ValidationException::withMessages(['transfer_request' => '振替先が満席のため承認できません。']);
            }

            if (ReservationRequest::query()
                ->where('student_profile_id', $lockedTransfer->student_profile_id)
                ->where('lesson_slot_id', $requestedSlot->id)
                ->exists()) {
                throw ValidationException::withMessages(['transfer_request' => '生徒は振替先の枠にすでに予約履歴があります。']);
            }

            $studioFeeAmount = $originalReservation->studio_fee_amount;
            $studioFeePricedOn = $originalReservation->studio_fee_priced_on;
            if ($studioFeeAmount === null && $originalReservation->lessonEnrollment !== null) {
                $quote = $this->lessonPricing->forEnrollment($originalReservation->lessonEnrollment, $originalReservation->lessonSlot->starts_at);
                $studioFeeAmount = $quote->studioFeePerLesson;
                $studioFeePricedOn = $originalReservation->lessonSlot->starts_at->toDateString();
            }

            $resultingReservation = ReservationRequest::create([
                'student_profile_id' => $lockedTransfer->student_profile_id,
                'lesson_slot_id' => $requestedSlot->id,
                'lesson_enrollment_id' => $originalReservation->lesson_enrollment_id,
                'lesson_entitlement_month' => ($originalReservation->lesson_entitlement_month
                    ?? $originalReservation->lessonSlot->starts_at->startOfMonth())->toDateString(),
                'studio_fee_amount' => $studioFeeAmount,
                'studio_fee_priced_on' => $studioFeePricedOn,
                'status' => ReservationStatus::Approved,
                'requested_at' => $lockedTransfer->requested_at,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'student_note' => $lockedTransfer->student_note,
                'staff_note' => $staffNote,
            ]);

            $originalReservation->update([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => '振替申請が承認されました。',
                'studio_fee_amount' => null,
                'studio_fee_priced_on' => null,
            ]);

            $lockedTransfer->update([
                'resulting_reservation_request_id' => $resultingReservation->id,
                'status' => ApplicationStatus::Approved,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'staff_note' => $staffNote,
            ]);

            return $lockedTransfer->refresh();
        }, 3);

        $this->notifications->transferReviewed($reviewedTransfer, $decision);

        return $reviewedTransfer;
    }
}
