<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\MonthlyInvoice;
use Illuminate\Notifications\Messages\MailMessage;

class MonthlyInvoicePaidNotification extends Concerns\QueuedMusakoNotification
{
    public function __construct(public MonthlyInvoice $invoice) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Billing;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【MUSAKOドラム教室】ご入金確認のお知らせ')
            ->greeting($this->invoice->studentProfile->user->name.'さん')
            ->line($this->invoice->invoice_number.'の入金を確認しました。')
            ->line('入金済み金額：¥'.number_format($this->invoice->paid_amount))
            ->action('請求内容を確認する', route('student.invoices.show', $this->invoice));
    }
}
