<?php

namespace App\Notifications;

use App\Enums\MonthlyInvoiceStatus;
use App\Enums\NotificationCategory;
use App\Models\MonthlyInvoice;
use Illuminate\Notifications\Messages\MailMessage;

class MonthlyInvoiceConfirmedNotification extends Concerns\QueuedMusakoNotification
{
    public function __construct(public MonthlyInvoice $invoice) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->invoice->fresh()?->status === MonthlyInvoiceStatus::Confirmed;
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Billing;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【MUSAKOドラム教室】'.$this->invoice->billing_month->format('n月').'分ご請求のお知らせ')
            ->greeting($this->invoice->studentProfile->user->name.'さん')
            ->line($this->invoice->billing_month->format('Y年n月').'分の請求を確定しました。')
            ->line('請求額：¥'.number_format($this->invoice->total_amount))
            ->line('支払期限：'.$this->invoice->due_on?->format('Y年n月j日'))
            ->action('請求内容を確認する', route('student.invoices.show', $this->invoice));
    }
}
