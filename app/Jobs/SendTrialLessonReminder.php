<?php

namespace App\Jobs;

use App\Enums\TrialLessonStatus;
use App\Models\TrialLessonReminderDelivery;
use App\Notifications\TrialLessonReminderNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendTrialLessonReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = TrialLessonReminderDelivery::query()
            ->with(['trialLessonRequest.lessonSlot.teacherProfile', 'trialLessonRequest.lessonSlot.venue', 'trialLessonRequest.lessonSlot.course'])
            ->findOrFail($this->deliveryId);
        if ($delivery->sent_at !== null) {
            return;
        }
        if ($delivery->trialLessonRequest->status !== TrialLessonStatus::Approved) {
            $delivery->update(['failed_at' => now(), 'last_error' => 'TrialNotApproved']);

            return;
        }
        $delivery->increment('attempts');
        try {
            Notification::route('mail', $delivery->trialLessonRequest->email)
                ->notifyNow(new TrialLessonReminderNotification($delivery->trialLessonRequest));
            $delivery->update(['sent_at' => now(), 'failed_at' => null, 'last_error' => null]);
        } catch (Throwable $exception) {
            $delivery->update(['failed_at' => now(), 'last_error' => class_basename($exception)]);
            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }
}
