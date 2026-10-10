<?php

namespace App\Notifications;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationCategory;
use App\Models\TransferRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class TransferApplicationNotification extends QueuedMusakoNotification
{
    public function __construct(
        public TransferRequest $transferRequest,
        public ApplicationStatus $eventStatus,
    ) {
        $this->undated = $transferRequest->requested_lesson_slot_id === null;
    }

    public bool $undated = false;

    public function category(): NotificationCategory
    {
        return NotificationCategory::Transfer;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->transferRequest->loadMissing([
            'studentProfile.user',
            'originalReservationRequest.lessonSlot.teacherProfile',
            'originalReservationRequest.lessonSlot.venue',
            'originalReservationRequest.lessonSlot.course',
            'requestedLessonSlot.teacherProfile',
            'requestedLessonSlot.venue',
            'requestedLessonSlot.course',
        ]);
        $pending = $this->eventStatus === ApplicationStatus::Pending;
        $approved = $this->eventStatus === ApplicationStatus::Approved;
        $mail = (new MailMessage)
            ->subject(match ($this->eventStatus) {
                ApplicationStatus::Pending => '【MUSAKOドラム教室】新しい振替申請があります',
                ApplicationStatus::Approved => $this->undated ? '【MUSAKOドラム教室】振替の日程保留が承認されました' : '【MUSAKOドラム教室】振替が確定しました',
                ApplicationStatus::Rejected => '【MUSAKOドラム教室】振替申請の結果',
            })
            ->greeting(match ($this->eventStatus) {
                ApplicationStatus::Pending => '新しい振替申請があります',
                ApplicationStatus::Approved => $this->undated ? '振替の日程保留が承認されました' : '振替が確定しました',
                ApplicationStatus::Rejected => '振替申請は却下されました',
            });

        if ($pending) {
            $mail->line('生徒：'.$this->transferRequest->studentProfile->user->name);
        }

        $mail->line('元レッスン')
            ->lines($this->lessonDetails($this->transferRequest->originalReservationRequest->lessonSlot))
            ->line($approved ? '新しいレッスン' : '希望した振替先');
        if ($this->undated) {
            $mail->line('日程未定（保留）。日程が決まり次第、先生が登録します。');
        } else {
            $mail->lines($this->lessonDetails($this->transferRequest->requestedLessonSlot));
        }

        if ($this->eventStatus === ApplicationStatus::Rejected && filled($this->transferRequest->staff_note)) {
            $mail->line('却下理由：'.$this->transferRequest->staff_note);
        }

        return $mail->action(
            $pending ? '振替申請を確認する' : '振替履歴を確認する',
            $pending
                ? route('staff.transfer-requests.show', $this->transferRequest)
                : route('student.transfer-requests.index'),
        );
    }
}
