<?php

namespace App\Actions;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewReservationRequest
{
    public function handle(ReservationRequest $reservationRequest, User $reviewer, ReservationStatus $decision, ?string $staffNote): ReservationRequest
    {
        return DB::transaction(function () use ($reservationRequest, $reviewer, $decision, $staffNote): ReservationRequest {
            $lockedReservation = ReservationRequest::query()->lockForUpdate()->findOrFail($reservationRequest->id);
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
            }

            $lockedReservation->update([
                'status' => $decision,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'staff_note' => $staffNote,
            ]);

            return $lockedReservation->refresh();
        }, 3);
    }
}
