<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use App\Services\MusakoNotificationService;
use App\Services\TeacherBookingAvailability;
use App\Services\TransferTermsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTransferRequest
{
    public function __construct(
        private readonly MusakoNotificationService $notifications,
        private readonly TeacherBookingAvailability $availability,
        private readonly TransferTermsService $terms,
    ) {}

    public function handle(
        StudentProfile $studentProfile,
        ReservationRequest $originalReservation,
        ?int $requestedLessonSlotId,
        ?string $reason,
        ?string $studentNote,
        bool $specialHandling = false,
    ): TransferRequest {
        $transferRequest = DB::transaction(function () use ($studentProfile, $originalReservation, $requestedLessonSlotId, $reason, $studentNote, $specialHandling): TransferRequest {
            StudentProfile::query()->lockForUpdate()->findOrFail($studentProfile->id);
            $lockedReservation = ReservationRequest::query()
                ->with('lessonSlot')
                ->lockForUpdate()
                ->findOrFail($originalReservation->id);
            $requestedSlot = $requestedLessonSlotId === null ? null : LessonSlot::query()->lockForUpdate()->findOrFail($requestedLessonSlotId);
            if ($requestedSlot !== null) {
                $this->availability->assertAvailable($requestedSlot, 'requested_lesson_slot_id', $lockedReservation->id);
            }

            if ($lockedReservation->student_profile_id !== $studentProfile->id
                || $lockedReservation->status !== ReservationStatus::Approved) {
                throw ValidationException::withMessages(['reservation' => '承認済みのご自身の予約だけ振替申請できます。']);
            }

            $this->terms->assertEligible($lockedReservation, $requestedSlot ?? $lockedReservation->lessonSlot, 'reservation');

            $now = CarbonImmutable::now(config('app.timezone'));
            if ($specialHandling && trim($reason ?? '') === '') {
                throw ValidationException::withMessages(['reason' => '特例を希望する理由を入力してください。']);
            }
            if (! $specialHandling && $now->greaterThan($lockedReservation->transferRequestDeadline())) {
                throw ValidationException::withMessages(['reservation' => '振替申請期限（前日12:00）を過ぎています。']);
            }

            if ($requestedSlot !== null) {
                if ($requestedSlot->id === $lockedReservation->lesson_slot_id) {
                    throw ValidationException::withMessages(['requested_lesson_slot_id' => '元の予約とは別の枠を選択してください。']);
                }

                if ($requestedSlot->status !== LessonSlotStatus::Open || ! $requestedSlot->starts_at->isFuture() || ! $requestedSlot->booking_audience->acceptsRegular()) {
                    throw ValidationException::withMessages(['requested_lesson_slot_id' => '選択した枠は現在予約できません。']);
                }

                if ($requestedSlot->reservationRequests()->where('status', ReservationStatus::Approved)->count() >= $requestedSlot->capacity) {
                    throw ValidationException::withMessages(['requested_lesson_slot_id' => '選択した枠は満席です。']);
                }

                if ($studentProfile->reservationRequests()->where('lesson_slot_id', $requestedSlot->id)->exists()) {
                    throw ValidationException::withMessages(['requested_lesson_slot_id' => '選択した枠にはすでに予約履歴があります。']);
                }

            }

            if ($lockedReservation->transferRequests()->where('status', ApplicationStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['reservation' => 'この予約には承認待ちの振替申請があります。']);
            }

            return TransferRequest::create([
                'student_profile_id' => $studentProfile->id,
                'original_reservation_request_id' => $lockedReservation->id,
                'requested_lesson_slot_id' => $requestedSlot?->id,
                'status' => ApplicationStatus::Pending,
                'reason' => $specialHandling ? '【特例希望・講師確認必要】'.$reason : $reason,
                'student_note' => $studentNote,
                'requested_at' => now(),
            ]);
        }, 3);

        $this->notifications->transferSubmitted($transferRequest);

        return $transferRequest;
    }
}
