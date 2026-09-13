<?php

namespace App\Notifications;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationCategory;
use App\Models\PaymentMethodChangeRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class PaymentMethodChangeApplicationNotification extends QueuedMusakoNotification
{
    public function __construct(
        public PaymentMethodChangeRequest $paymentMethodChangeRequest,
        public ApplicationStatus $eventStatus,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Procedure;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->paymentMethodChangeRequest->loadMissing('studentProfile.user');
        $pending = $this->eventStatus === ApplicationStatus::Pending;
        $mail = (new MailMessage)
            ->subject('【MUSAKOドラム教室】支払い方法変更'.($pending ? '申請があります' : '申請の結果'))
            ->greeting($pending ? '支払い方法変更申請があります' : '支払い方法変更申請は'.($this->eventStatus === ApplicationStatus::Approved ? '承認されました' : '却下されました'))
            ->line('希望する支払い方法：'.$this->paymentMethodChangeRequest->requested_method->label())
            ->line('カード番号・銀行口座番号などの情報はメールにもシステムにも保存していません。');

        if ($pending) {
            $mail->line('生徒：'.$this->paymentMethodChangeRequest->studentProfile->user->name);
        }
        if ($this->eventStatus === ApplicationStatus::Rejected && filled($this->paymentMethodChangeRequest->rejection_reason)) {
            $mail->line('却下理由：'.$this->paymentMethodChangeRequest->rejection_reason);
        }

        return $mail->action(
            $pending ? '申請を確認する' : '申請履歴を確認する',
            $pending
                ? route('staff.payment-method-change-requests.show', $this->paymentMethodChangeRequest)
                : route('student.payment-method-change-requests.index'),
        );
    }
}
