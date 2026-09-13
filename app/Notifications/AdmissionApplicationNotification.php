<?php

namespace App\Notifications;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\NotificationCategory;
use App\Models\AdmissionApplication;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class AdmissionApplicationNotification extends QueuedMusakoNotification
{
    public function __construct(private readonly AdmissionApplication $application, private readonly bool $forStaff) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Admission;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $application = $this->application->loadMissing(['course', 'teacherProfile', 'venue']);
        $mail = (new MailMessage)->subject(match ($application->status) {
            AdmissionApplicationStatus::Pending => '【MUSAKOドラム教室】入会申込みを受け付けました',
            AdmissionApplicationStatus::Approved => '【MUSAKOドラム教室】入会申込みが承認されました',
            AdmissionApplicationStatus::Rejected => '【MUSAKOドラム教室】入会申込み結果のお知らせ',
            default => '【MUSAKOドラム教室】入会申込みのお知らせ',
        });
        if ($this->forStaff) {
            return $mail->greeting('新しい入会申込みがあります。')
                ->line('受付番号：'.$application->public_reference)
                ->line('申込者：'.$application->name)
                ->line('希望コース：'.$application->course->name)
                ->line('契約：'.$application->lesson_type->label().'・'.$application->pricing_category->label().'・月'.$application->monthly_lesson_count.'回')
                ->action('管理画面で確認する', route('staff.admission-applications.show', $application));
        }

        $mail->greeting($application->name.' 様')->line('受付番号：'.$application->public_reference);
        if ($application->status === AdmissionApplicationStatus::Pending) {
            $mail->line('入会申込みを受け付けました。確認後、結果をご連絡します。');
        } elseif ($application->status === AdmissionApplicationStatus::Approved) {
            $mail->line('入会申込みを承認しました。別メールの初回ログイン案内からパスワードを設定してください。');
        } elseif ($application->status === AdmissionApplicationStatus::Rejected) {
            $mail->line('入会申込みを承認できませんでした。')
                ->when($application->rejection_reason !== null, fn (MailMessage $message) => $message->line('理由：'.$application->rejection_reason));
        }

        return $mail;
    }
}
