<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\MonthlyInvoice;
use Illuminate\Notifications\Messages\MailMessage;

class MonthlyInvoiceCancelledNotification extends Concerns\QueuedMusakoNotification
{
    public function __construct(public MonthlyInvoice $invoice) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Billing;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【MUSAKOドラム教室】請求取消のお知らせ')
            ->line('請求番号 '.$this->invoice->invoice_number.' は取消済みです。')
            ->line('この請求のお支払いは不要です。再発行する場合は、確認後に新しい請求番号でお知らせします。')
            ->action('請求履歴を確認する', route('student.invoices.show', $this->invoice));
    }
}
