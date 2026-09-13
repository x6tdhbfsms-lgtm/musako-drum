<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\ReservationRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationCancelledNotification extends QueuedMusakoNotification
{
    public function __construct(public ReservationRequest $reservationRequest) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Reservation;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->reservationRequest->loadMissing(['studentProfile.user', 'lessonSlot.teacherProfile', 'lessonSlot.venue', 'lessonSlot.course']);
        $mail = (new MailMessage)
            ->subject('【MUSAKOドラム教室】予約がキャンセルされました')
            ->greeting('生徒から予約キャンセルがありました')
            ->line('生徒：'.$this->reservationRequest->studentProfile->user->name)
            ->lines($this->lessonDetails($this->reservationRequest->lessonSlot));

        if (filled($this->reservationRequest->cancellation_reason)) {
            $mail->line('理由：'.$this->reservationRequest->cancellation_reason);
        }

        return $mail->action('予約詳細を確認する', route('staff.reservations.show', $this->reservationRequest));
    }
}
