<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTransferRequest
{
    public function handle(
        StudentProfile $studentProfile,
        ReservationRequest $originalReservation,
        int $requestedLessonSlotId,
        ?string $reason,
        ?string $studentNote,
    ): TransferRequest {
        return DB::transaction(function () use ($studentProfile, $originalReservation, $requestedLessonSlotId, $reason, $studentNote): TransferRequest {
            $lockedReservation = ReservationRequest::query()
                ->with('lessonSlot')
                ->lockForUpdate()
                ->findOrFail($originalReservation->id);
            $requestedSlot = LessonSlot::query()->lockForUpdate()->findOrFail($requestedLessonSlotId);

            if ($lockedReservation->student_profile_id !== $studentProfile->id
                || $lockedReservation->status !== ReservationStatus::Approved) {
                throw ValidationException::withMessages(['reservation' => '承認済みのご自身の予約だけ振替申請できます。']);
            }

            $now = CarbonImmutable::now(config('app.timezone'));
            if ($now->greaterThan($lockedReservation->transferRequestDeadline())) {
                throw ValidationException::withMessages(['reservation' => '振替申請期限（前日19:00）を過ぎています。']);
            }

            if ($requestedSlot->id === $lockedReservation->lesson_slot_id) {
                throw ValidationException::withMessages(['requested_lesson_slot_id' => '元の予約とは別の枠を選択してください。']);
            }

            if ($requestedSlot->status !== LessonSlotStatus::Open || ! $requestedSlot->starts_at->isFuture()) {
                throw ValidationException::withMessages(['requested_lesson_slot_id' => '選択した枠は現在予約できません。']);
            }

            if ($requestedSlot->reservationRequests()->where('status', ReservationStatus::Approved)->count() >= $requestedSlot->capacity) {
                throw ValidationException::withMessages(['requested_lesson_slot_id' => '選択した枠は満席です。']);
            }

            if ($studentProfile->reservationRequests()->where('lesson_slot_id', $requestedSlot->id)->exists()) {
                throw ValidationException::withMessages(['requested_lesson_slot_id' => '選択した枠にはすでに予約履歴があります。']);
            }

            if ($lockedReservation->transferRequests()->where('status', ApplicationStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['reservation' => 'この予約には承認待ちの振替申請があります。']);
            }

            return TransferRequest::create([
                'student_profile_id' => $studentProfile->id,
                'original_reservation_request_id' => $lockedReservation->id,
                'requested_lesson_slot_id' => $requestedSlot->id,
                'status' => ApplicationStatus::Pending,
                'reason' => $reason,
                'student_note' => $studentNote,
                'requested_at' => now(),
            ]);
        }, 3);
    }
}
