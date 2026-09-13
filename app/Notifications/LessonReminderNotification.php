<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\ReservationRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class LessonReminderNotification extends QueuedMusakoNotification
{
    public function __construct(public ReservationRequest $reservationRequest) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Reminder;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->reservationRequest->loadMissing(['lessonSlot.teacherProfile', 'lessonSlot.venue', 'lessonSlot.course']);

        return (new MailMessage)
            ->subject('【MUSAKOドラム教室】明日のレッスンのお知らせ')
            ->greeting('明日のレッスンのお知らせ')
            ->lines($this->lessonDetails($this->reservationRequest->lessonSlot))
            ->line('お休み・遅刻・振替の連絡はマイページから行えます。')
            ->action('マイページで確認する', route('student.reservations.show', $this->reservationRequest));
    }
}
