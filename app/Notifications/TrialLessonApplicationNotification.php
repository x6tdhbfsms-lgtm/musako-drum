<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\TrialLessonStatus;
use App\Models\TrialLessonRequest;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;

class TrialLessonApplicationNotification extends QueuedMusakoNotification
{
    private readonly ?string $encryptedAccessToken;

    public function __construct(
        private readonly TrialLessonRequest $trialLessonRequest,
        private readonly bool $forStaff,
        ?string $accessToken = null,
    ) {
        $this->encryptedAccessToken = $accessToken === null ? null : Crypt::encryptString($accessToken);
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Trial;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->trialLessonRequest->loadMissing('lessonSlot.teacherProfile', 'lessonSlot.venue', 'lessonSlot.course');
        $status = $request->status;
        $mail = (new MailMessage)->subject(match ($status) {
            TrialLessonStatus::Pending => '【MUSAKOドラム教室】体験レッスン申込みを受け付けました',
            TrialLessonStatus::Approved => '【MUSAKOドラム教室】体験レッスンの日時が確定しました',
            TrialLessonStatus::Rejected => '【MUSAKOドラム教室】体験レッスン申込み結果のお知らせ',
            TrialLessonStatus::Cancelled => '【MUSAKOドラム教室】体験レッスンがキャンセルされました',
            default => '【MUSAKOドラム教室】体験レッスンのお知らせ',
        });

        if ($this->forStaff) {
            $mail->greeting('新しい体験レッスン申込みがあります。')
                ->line('受付番号：'.$request->public_reference)
                ->line('申込者：'.$request->name)
                ->lines($this->lessonDetails($request->lessonSlot))
                ->line('経験：'.$request->drum_experience)
                ->when($request->consultation !== null, fn (MailMessage $message) => $message->line('相談内容：'.$request->consultation))
                ->action('管理画面で確認する', route('staff.trial-lessons.show', $request));

            return $mail;
        }

        $mail->greeting($request->name.' 様')
            ->line('受付番号：'.$request->public_reference)
            ->lines($this->lessonDetails($request->lessonSlot));
        if ($status === TrialLessonStatus::Pending) {
            $mail->line('体験レッスン申込みを受け付けました。教室で確認後、結果をお知らせします。');
        } elseif ($status === TrialLessonStatus::Approved) {
            $mail->line('上記日時で体験レッスンを承認しました。');
        } elseif ($status === TrialLessonStatus::Rejected) {
            $mail->line('ご希望の申込みを承認できませんでした。')
                ->when($request->rejection_reason !== null, fn (MailMessage $message) => $message->line('理由：'.$request->rejection_reason))
                ->action('別の日時を確認する', route('trial-lessons.index'));
        }
        if ($this->encryptedAccessToken !== null && in_array($status, [TrialLessonStatus::Pending, TrialLessonStatus::Approved], true)) {
            $mail->action('申込みの確認・キャンセル', route('trial-lessons.manage', [$request->public_reference, Crypt::decryptString($this->encryptedAccessToken)]));
        }

        return $mail;
    }
}
