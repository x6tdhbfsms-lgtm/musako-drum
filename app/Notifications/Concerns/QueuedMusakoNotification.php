<?php

namespace App\Notifications\Concerns;

use App\Enums\NotificationCategory;
use App\Models\LessonSlot;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class QueuedMusakoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    abstract public function category(): NotificationCategory;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User && ! $notifiable->canReceiveEmailNotification($this->category())) {
            return [];
        }

        return ['mail'];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    protected function lessonDetails(LessonSlot $lessonSlot): array
    {
        $lessonSlot->loadMissing(['teacherProfile', 'venue', 'course']);

        return [
            '日時：'.$lessonSlot->starts_at->setTimezone(config('app.timezone'))->format('Y年n月j日 H:i').'〜'.$lessonSlot->ends_at->setTimezone(config('app.timezone'))->format('H:i'),
            '担当：'.($lessonSlot->teacherProfile?->display_name ?? '未定'),
            '会場：'.($lessonSlot->venue?->name ?? '未定'),
            'コース：'.($lessonSlot->course?->name ?? '未定'),
        ];
    }
}
