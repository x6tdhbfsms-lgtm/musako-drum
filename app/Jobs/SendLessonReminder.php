<?php

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Enums\ReservationStatus;
use App\Models\LessonReminderDelivery;
use App\Notifications\LessonReminderNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendLessonReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public int $lessonReminderDeliveryId) {}

    public function handle(): void
    {
        $delivery = LessonReminderDelivery::query()
            ->with(['user', 'reservationRequest.lessonSlot.teacherProfile', 'reservationRequest.lessonSlot.venue', 'reservationRequest.lessonSlot.course'])
            ->findOrFail($this->lessonReminderDeliveryId);

        if ($delivery->sent_at !== null) {
            return;
        }

        if ($delivery->reservationRequest->status !== ReservationStatus::Approved) {
            $delivery->update(['failed_at' => now(), 'last_error' => 'ReservationNotApproved']);

            return;
        }

        if (! $delivery->user->canReceiveEmailNotification(NotificationCategory::Reminder)) {
            $delivery->update(['failed_at' => now(), 'last_error' => 'RecipientUnavailable']);

            return;
        }

        $delivery->increment('attempts');

        try {
            $delivery->user->notifyNow(new LessonReminderNotification($delivery->reservationRequest));
            $delivery->update([
                'sent_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $delivery->update([
                'failed_at' => now(),
                'last_error' => class_basename($exception),
            ]);

            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->lessonReminderDeliveryId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }
}
