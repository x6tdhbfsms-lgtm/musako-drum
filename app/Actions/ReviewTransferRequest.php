<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\LessonSlotCapacityService;
use App\Services\MusakoNotificationService;
use App\Services\TeacherBookingAvailability;
use App\Services\TransferTermsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewTransferRequest
{
    public function __construct(
        private readonly TeacherBookingAvailability $availability,
        private readonly TransferTermsService $terms,
        private readonly LessonSlotCapacityService $capacity,
        private readonly MusakoNotificationService $notifications,
    ) {}

    public function handle(
        TransferRequest $transferRequest,
        User $reviewer,
        ApplicationStatus $decision,
        ?string $staffNote,
        string $cause = 'student',
    ): TransferRequest {
        $reviewedTransfer = DB::transaction(function () use ($transferRequest, $reviewer, $decision, $staffNote, $cause): TransferRequest {
            StudentProfile::query()->lockForUpdate()->findOrFail($transferRequest->student_profile_id);
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

            if ($requestedSlot !== null && ($requestedSlot->status !== LessonSlotStatus::Open || ! $requestedSlot->starts_at->isFuture() || ! $requestedSlot->booking_audience->acceptsRegular())) {
                throw ValidationException::withMessages(['transfer_request' => '振替先の枠は現在予約できません。']);
            }

            if (! in_array($cause, ['student', 'teacher', 'emergency'], true)) {
                throw ValidationException::withMessages(['cause' => '振替の理由区分を選択してください。']);
            }
            if ($cause !== 'student' && trim($staffNote ?? '') === '') {
                throw ValidationException::withMessages(['staff_note' => '講師都合・災害等の特例は理由を記録してください。']);
            }
            $formalizedAt = CarbonImmutable::now(config('app.timezone'));
            if ($cause === 'student' && $formalizedAt->gt($originalReservation->transferRequestDeadline())) {
                throw ValidationException::withMessages(['transfer_request' => '正式な申請期限（前日12:00）を過ぎています。講師の確定日時で判定します。']);
            }

            $this->terms->assertEligible($originalReservation, $requestedSlot ?? $originalReservation->lessonSlot, 'transfer_request');
            if ($requestedSlot === null) {
                if ($originalReservation->studio_fee_amount === null || $originalReservation->studio_fee_priced_on === null) {
                    throw ValidationException::withMessages(['transfer_request' => '元予約のスタジオ料金記録が未設定です。管理者による確認が必要です。']);
                }
                $snapshot = $this->terms->quote($lockedTransfer, $formalizedAt, $cause);
                $snapshot['exception_reason'] = $cause === 'student' ? null : $staffNote;
                $snapshot['deferred_studio_fee_amount'] = $originalReservation->studio_fee_amount;
                $snapshot['deferred_studio_fee_priced_on'] = $originalReservation->studio_fee_priced_on->toDateString();
                $originalReservation->update([
                    'status' => ReservationStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_reason' => '日程未定の振替として保留承認されました。',
                    'studio_fee_amount' => null,
                    'studio_fee_priced_on' => null,
                ]);
                $lockedTransfer->update([
                    'status' => ApplicationStatus::Approved, 'terms_snapshot' => $snapshot,
                    'reviewed_by_user_id' => $reviewer->id, 'reviewed_at' => now(), 'staff_note' => $staffNote,
                ]);

                return $lockedTransfer->refresh();
            }
            $this->availability->assertAvailable($requestedSlot, 'transfer_request', $originalReservation->id);

            if (! $this->capacity->hasCapacity($requestedSlot)) {
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
            if ($studioFeeAmount === null || $studioFeePricedOn === null) {
                throw ValidationException::withMessages([
                    'transfer_request' => '元予約のスタジオ料金記録が未設定です。管理者が当時の料金と履歴を確認するまで振替を承認できません。',
                ]);
            }

            $termsSnapshot = $this->terms->quote($lockedTransfer, $formalizedAt, $cause);
            $termsSnapshot['exception_reason'] = $cause === 'student' ? null : $staffNote;
            $targetFee = match ($requestedSlot->venue?->termsRoomType()) {
                'music_room' => 1000,
                'studio34' => 1610,
                default => $studioFeeAmount,
            };
            $resultingReservation = ReservationRequest::create([
                'student_profile_id' => $lockedTransfer->student_profile_id,
                'lesson_slot_id' => $requestedSlot->id,
                'lesson_enrollment_id' => $originalReservation->lesson_enrollment_id,
                'lesson_entitlement_month' => ($originalReservation->lesson_entitlement_month
                    ?? $originalReservation->lessonSlot->starts_at->startOfMonth())->toDateString(),
                'studio_fee_amount' => $targetFee,
                'studio_fee_priced_on' => $requestedSlot->starts_at->toDateString(),
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
                'terms_snapshot' => $termsSnapshot,
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
