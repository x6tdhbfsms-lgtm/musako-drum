<?php

namespace App\Actions;

use App\Enums\LessonSlotStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;
use App\Models\TrialLessonRequest;
use App\Models\User;
use App\Services\LessonSlotCapacityService;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewTrialLessonRequestAction
{
    public function __construct(
        private readonly LessonSlotCapacityService $capacity,
        private readonly MusakoNotificationService $notifications,
    ) {}

    public function handle(TrialLessonRequest $trialLessonRequest, User $reviewer, TrialLessonStatus $decision, ?string $reason): TrialLessonRequest
    {
        $reviewed = DB::transaction(function () use ($trialLessonRequest, $reviewer, $decision, $reason): TrialLessonRequest {
            $request = TrialLessonRequest::query()->lockForUpdate()->findOrFail($trialLessonRequest->id);
            $slot = LessonSlot::query()->lockForUpdate()->findOrFail($request->lesson_slot_id);
            $from = $request->status;

            if (in_array($decision, [TrialLessonStatus::Approved, TrialLessonStatus::Rejected], true)
                && $from !== TrialLessonStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'この申込みはすでに処理されています。']);
            }
            if (in_array($decision, [TrialLessonStatus::Completed, TrialLessonStatus::NoShow], true)
                && $from !== TrialLessonStatus::Approved) {
                throw ValidationException::withMessages(['status' => '承認済みの体験だけ完了または欠席にできます。']);
            }
            if ($decision === TrialLessonStatus::Approved
                && ($slot->status !== LessonSlotStatus::Open || ! $slot->starts_at->isFuture()
                    || $this->capacity->occupiedSeats($slot) - ($from === TrialLessonStatus::Approved ? 1 : 0) >= $slot->capacity)) {
                throw ValidationException::withMessages(['status' => '定員に達しているため承認できません。']);
            }

            $timestamps = match ($decision) {
                TrialLessonStatus::Approved => ['approved_at' => now()],
                TrialLessonStatus::Rejected => ['rejected_at' => now()],
                TrialLessonStatus::Completed => ['completed_at' => now()],
                TrialLessonStatus::NoShow => ['no_show_at' => now()],
                default => [],
            };
            $request->update([
                'status' => $decision,
                'active_slot_key' => in_array($decision, [TrialLessonStatus::Pending, TrialLessonStatus::Approved], true) ? $request->active_slot_key : null,
                'processed_by_user_id' => $reviewer->id,
                'processed_at' => now(),
                'rejection_reason' => $decision === TrialLessonStatus::Rejected ? $reason : null,
                ...$timestamps,
            ]);
            $request->events()->create([
                'event_type' => $decision->value,
                'from_status' => $from->value,
                'to_status' => $decision->value,
                'actor_user_id' => $reviewer->id,
                'note' => $reason,
                'occurred_at' => now(),
            ]);

            return $request->refresh();
        }, 3);

        if (in_array($decision, [TrialLessonStatus::Approved, TrialLessonStatus::Rejected], true)) {
            $this->notifications->trialReviewed($reviewed, $decision);
        }

        return $reviewed;
    }
}
