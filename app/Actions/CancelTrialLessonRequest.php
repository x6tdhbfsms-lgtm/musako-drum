<?php

namespace App\Actions;

use App\Enums\TrialLessonStatus;
use App\Models\TrialLessonRequest;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelTrialLessonRequest
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    public function handle(TrialLessonRequest $trialLessonRequest): TrialLessonRequest
    {
        $cancelled = DB::transaction(function () use ($trialLessonRequest): TrialLessonRequest {
            $request = TrialLessonRequest::query()->lockForUpdate()->findOrFail($trialLessonRequest->id);
            if (! in_array($request->status, [TrialLessonStatus::Pending, TrialLessonStatus::Approved], true)) {
                throw ValidationException::withMessages(['trial' => 'この体験申込みはキャンセルできません。']);
            }
            $from = $request->status;
            $request->update(['status' => TrialLessonStatus::Cancelled, 'active_slot_key' => null, 'cancelled_at' => now()]);
            $request->events()->create([
                'event_type' => 'cancelled',
                'from_status' => $from->value,
                'to_status' => TrialLessonStatus::Cancelled->value,
                'occurred_at' => now(),
            ]);

            return $request->refresh();
        }, 3);

        $this->notifications->trialCancelled($cancelled);

        return $cancelled;
    }
}
