<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;

class InitialPasswordSetupNotification extends QueuedMusakoNotification
{
    private readonly string $encryptedToken;

    public function __construct(private readonly string $email, string $token)
    {
        $this->encryptedToken = Crypt::encryptString($token);
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Admission;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【MUSAKOドラム教室】初回ログインのご案内')
            ->greeting('生徒アカウントを作成しました。')
            ->line('安全のため、パスワードはメールに記載していません。以下の期限付きリンクからご自身で設定してください。')
            ->action('パスワードを設定する', route('password.reset', ['token' => Crypt::decryptString($this->encryptedToken), 'email' => $this->email]))
            ->line('このリンクは60分間有効で、一度だけ利用できます。');
    }
}
