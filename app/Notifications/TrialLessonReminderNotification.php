<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\TrialLessonRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class TrialLessonReminderNotification extends QueuedMusakoNotification
{
    public function __construct(private readonly TrialLessonRequest $trialLessonRequest) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Reminder;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【MUSAKOドラム教室】明日の体験レッスンのお知らせ')
            ->greeting($this->trialLessonRequest->name.' 様')
            ->lines($this->lessonDetails($this->trialLessonRequest->lessonSlot))
            ->line('明日の体験レッスンをお待ちしています。');
    }
}
