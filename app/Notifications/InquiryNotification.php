<?php

namespace App\Notifications;

use App\Enums\InquiryStatus;
use App\Enums\NotificationCategory;
use App\Models\Inquiry;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class InquiryNotification extends QueuedMusakoNotification
{
    public function __construct(
        public Inquiry $inquiry,
        public InquiryStatus $eventStatus,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Inquiry;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->inquiry->loadMissing('studentProfile.user');
        $resolved = $this->eventStatus === InquiryStatus::Resolved;
        $mail = (new MailMessage)
            ->subject($resolved ? '【MUSAKOドラム教室】お問い合わせを解決済みにしました' : '【MUSAKOドラム教室】新しいお問い合わせがあります')
            ->greeting($resolved ? 'お問い合わせの対応状況が更新されました' : '新しいお問い合わせがあります')
            ->line('件名：'.$this->inquiry->subject)
            ->line('状態：'.$this->eventStatus->label());

        if (! $resolved) {
            $mail->line('生徒：'.$this->inquiry->studentProfile->user->name);
        }

        return $mail->action(
            $resolved ? 'お問い合わせを確認する' : '管理画面で確認する',
            $resolved
                ? route('student.inquiries.show', $this->inquiry)
                : route('staff.inquiries.show', $this->inquiry),
        );
    }
}
