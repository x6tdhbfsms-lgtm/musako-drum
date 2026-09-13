<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Jobs\SendLessonReminder;
use App\Jobs\SendTrialLessonReminder;
use App\Models\LessonReminderDelivery;
use App\Models\ReservationRequest;
use App\Models\TrialLessonReminderDelivery;
use App\Models\TrialLessonRequest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('lesson-reminders:send {--date= : Reminder target lesson date (Y-m-d)}')]
#[Description('Queue reminder emails for approved lessons on the following day')]
class SendLessonReminders extends Command
{
    public function handle(): int
    {
        $lessonOn = $this->option('date') !== null
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('date'), config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'))->addDay()->startOfDay();
        $queuedCount = 0;

        ReservationRequest::query()
            ->with(['studentProfile.user', 'lessonSlot'])
            ->where('status', ReservationStatus::Approved)
            ->whereHas('lessonSlot', fn ($query) => $query->whereBetween('starts_at', [$lessonOn->startOfDay(), $lessonOn->endOfDay()]))
            ->whereDoesntHave('lessonReminderDelivery')
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use (&$queuedCount, $lessonOn): void {
                foreach ($reservations as $reservationRequest) {
                    $student = $reservationRequest->studentProfile->user;
                    if (! $student->canReceiveEmailNotification(NotificationCategory::Reminder)) {
                        continue;
                    }

                    $delivery = DB::transaction(function () use ($reservationRequest, $student, $lessonOn): ?LessonReminderDelivery {
                        $lockedReservation = ReservationRequest::query()->lockForUpdate()->findOrFail($reservationRequest->id);
                        if ($lockedReservation->status !== ReservationStatus::Approved) {
                            return null;
                        }

                        return LessonReminderDelivery::query()->firstOrCreate(
                            ['reservation_request_id' => $lockedReservation->id],
                            [
                                'user_id' => $student->id,
                                'lesson_on' => $lessonOn->toDateString(),
                                'queued_at' => now(),
                            ],
                        );
                    }, 3);

                    if ($delivery === null || $delivery->wasRecentlyCreated === false) {
                        continue;
                    }

                    SendLessonReminder::dispatch($delivery->id)
                        ->onQueue((string) config('musako.notifications.queue'));
                    $queuedCount++;
                }
            });

        TrialLessonRequest::query()
            ->with('lessonSlot')
            ->where('status', TrialLessonStatus::Approved)
            ->whereHas('lessonSlot', fn ($query) => $query->whereBetween('starts_at', [$lessonOn->startOfDay(), $lessonOn->endOfDay()]))
            ->whereDoesntHave('reminderDelivery')
            ->orderBy('id')
            ->chunkById(100, function ($trialRequests) use (&$queuedCount, $lessonOn): void {
                foreach ($trialRequests as $trialRequest) {
                    $delivery = DB::transaction(function () use ($trialRequest, $lessonOn): ?TrialLessonReminderDelivery {
                        $locked = TrialLessonRequest::query()->lockForUpdate()->findOrFail($trialRequest->id);
                        if ($locked->status !== TrialLessonStatus::Approved) {
                            return null;
                        }

                        return TrialLessonReminderDelivery::query()->firstOrCreate(
                            ['trial_lesson_request_id' => $locked->id],
                            ['lesson_on' => $lessonOn->toDateString(), 'queued_at' => now()],
                        );
                    }, 3);
                    if ($delivery === null || ! $delivery->wasRecentlyCreated) {
                        continue;
                    }
                    SendTrialLessonReminder::dispatch($delivery->id)->onQueue((string) config('musako.notifications.queue'));
                    $queuedCount++;
                }
            });

        $this->info($lessonOn->format('Y-m-d').' の前日リマインダーを '.$queuedCount.'件キューへ登録しました。');

        return self::SUCCESS;
    }
}
