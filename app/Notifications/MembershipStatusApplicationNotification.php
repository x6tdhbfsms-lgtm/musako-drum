<?php

namespace App\Notifications;

use App\Enums\ApplicationStatus;
use App\Enums\MembershipRequestType;
use App\Enums\NotificationCategory;
use App\Models\MembershipStatusRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class MembershipStatusApplicationNotification extends QueuedMusakoNotification
{
    public function __construct(
        public MembershipStatusRequest $membershipStatusRequest,
        public ApplicationStatus $eventStatus,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Procedure;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->membershipStatusRequest->loadMissing('studentProfile.user');
        $type = match ($this->membershipStatusRequest->type) {
            MembershipRequestType::Pause => '休会',
            MembershipRequestType::Withdraw => '退会',
            MembershipRequestType::Resume => '再開',
        };
        $pending = $this->eventStatus === ApplicationStatus::Pending;
        $mail = (new MailMessage)
            ->subject('【MUSAKOドラム教室】'.$type.($pending ? '申請があります' : '申請の結果'))
            ->greeting($pending ? $type.'申請があります' : $type.'申請は'.($this->eventStatus === ApplicationStatus::Approved ? '承認されました' : '却下されました'));

        if ($pending) {
            $mail->line('生徒：'.$this->membershipStatusRequest->studentProfile->user->name);
        }

        $mail->line('希望日：'.$this->membershipStatusRequest->effective_on->format('Y年n月j日'));
        if ($this->eventStatus === ApplicationStatus::Rejected && filled($this->membershipStatusRequest->staff_note)) {
            $mail->line('却下理由：'.$this->membershipStatusRequest->staff_note);
        }

        return $mail->action(
            $pending ? '申請を確認する' : '申請履歴を確認する',
            $pending
                ? route('staff.membership-status-requests.show', $this->membershipStatusRequest)
                : route('student.membership-status-requests.index'),
        );
    }
}
