<?php

namespace App\Notifications;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationCategory;
use App\Models\ContractChangeRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ContractChangeApplicationNotification extends QueuedMusakoNotification
{
    public function __construct(
        public ContractChangeRequest $contractChangeRequest,
        public ApplicationStatus $eventStatus,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Procedure;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->contractChangeRequest->loadMissing('studentProfile.user');
        $pending = $this->eventStatus === ApplicationStatus::Pending;
        $mail = (new MailMessage)
            ->subject('【MUSAKOドラム教室】契約内容変更'.($pending ? '申請があります' : '申請の結果'))
            ->greeting($pending ? '契約内容変更申請があります' : '契約内容変更申請は'.($this->eventStatus === ApplicationStatus::Approved ? '承認されました' : '却下されました'))
            ->line('手続き：'.$this->contractChangeRequest->type->label())
            ->line('希望適用日：'.$this->contractChangeRequest->effective_on->format('Y年n月j日'));

        if ($pending) {
            $mail->line('生徒：'.$this->contractChangeRequest->studentProfile->user->name);
        }
        if ($this->eventStatus === ApplicationStatus::Rejected && filled($this->contractChangeRequest->rejection_reason)) {
            $mail->line('却下理由：'.$this->contractChangeRequest->rejection_reason);
        }

        return $mail->action(
            $pending ? '申請を確認する' : '申請履歴を確認する',
            $pending
                ? route('staff.contract-change-requests.show', $this->contractChangeRequest)
                : route('student.contract-change-requests.index', ['type' => $this->contractChangeRequest->type->value]),
        );
    }
}
