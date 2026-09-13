<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationApplicationNotification extends QueuedMusakoNotification
{
    public function __construct(
        public ReservationRequest $reservationRequest,
        public ReservationStatus $eventStatus,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Reservation;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->reservationRequest->loadMissing(['studentProfile.user', 'lessonSlot.teacherProfile', 'lessonSlot.venue', 'lessonSlot.course']);
        $slot = $this->reservationRequest->lessonSlot;

        if ($this->eventStatus === ReservationStatus::Pending) {
            return (new MailMessage)
                ->subject('【MUSAKOドラム教室】新しい予約申請があります')
                ->greeting('新しい予約申請があります')
                ->line('生徒：'.$this->reservationRequest->studentProfile->user->name)
                ->lines($this->lessonDetails($slot))
                ->line('申請日時：'.$this->reservationRequest->requested_at->setTimezone(config('app.timezone'))->format('Y年n月j日 H:i'))
                ->action('予約申請を確認する', route('staff.reservations.show', $this->reservationRequest));
        }

        $approved = $this->eventStatus === ReservationStatus::Approved;
        $mail = (new MailMessage)
            ->subject($approved ? '【MUSAKOドラム教室】予約が確定しました' : '【MUSAKOドラム教室】予約申請の結果')
            ->greeting($approved ? '予約が確定しました' : '予約申請は却下されました')
            ->lines($this->lessonDetails($slot));

        if (! $approved && filled($this->reservationRequest->staff_note)) {
            $mail->line('却下理由：'.$this->reservationRequest->staff_note);
        }

        return $mail->action(
            $approved ? '予約詳細を確認する' : 'カレンダーから再予約する',
            $approved
                ? route('student.reservations.show', $this->reservationRequest)
                : route('student.dashboard', ['month' => $slot->starts_at->format('Y-m')]),
        );
    }
}
