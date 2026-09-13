<?php

namespace App\Notifications;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationCategory;
use App\Models\PersonalInformationChangeRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class PersonalInformationChangeApplicationNotification extends QueuedMusakoNotification
{
    public function __construct(
        public PersonalInformationChangeRequest $personalInformationChangeRequest,
        public ApplicationStatus $eventStatus,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Procedure;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->personalInformationChangeRequest->loadMissing('studentProfile.user');
        $pending = $this->eventStatus === ApplicationStatus::Pending;
        $mail = (new MailMessage)
            ->subject('【MUSAKOドラム教室】個人情報変更'.($pending ? '申請があります' : '申請の結果'))
            ->greeting($pending ? '個人情報変更申請があります' : '個人情報変更申請は'.($this->eventStatus === ApplicationStatus::Approved ? '承認されました' : '却下されました'))
            ->line('安全のため、変更する個人情報の内容はメールに記載していません。');

        if ($pending) {
            $mail->line('生徒：'.$this->personalInformationChangeRequest->studentProfile->user->name);
        }
        if ($this->eventStatus === ApplicationStatus::Rejected && filled($this->personalInformationChangeRequest->rejection_reason)) {
            $mail->line('却下理由：'.$this->personalInformationChangeRequest->rejection_reason);
        }

        return $mail->action(
            $pending ? '申請を確認する' : '申請履歴を確認する',
            $pending
                ? route('staff.personal-information-change-requests.show', $this->personalInformationChangeRequest)
                : route('student.personal-information-change-requests.index'),
        );
    }
}
