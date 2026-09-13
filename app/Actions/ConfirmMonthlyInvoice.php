<?php

namespace App\Actions;

use App\Enums\MonthlyInvoiceStatus;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\User;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmMonthlyInvoice
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    public function handle(MonthlyInvoice $invoice, User $actor, bool $acknowledgeWarnings = false): MonthlyInvoice
    {
        $confirmed = DB::transaction(function () use ($invoice, $actor, $acknowledgeWarnings): MonthlyInvoice {
            $locked = MonthlyInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== MonthlyInvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => 'この請求は下書きではありません。']);
            }
            if ($locked->requires_review && ! $acknowledgeWarnings) {
                throw ValidationException::withMessages(['acknowledge_warnings' => '警告内容を確認してから確定してください。']);
            }
            $hasMissingRate = collect($locked->pricing_snapshot ?? [])->contains(
                fn (array $snapshot): bool => ($snapshot['base_lesson_fee'] ?? null) === null,
            );
            if ($hasMissingRate && ! $locked->items()->where('is_manual', true)->where('amount', '>', 0)->exists()) {
                throw ValidationException::withMessages(['invoice' => '要相談料金は、確認済みの料金を手動明細として追加してから確定してください。']);
            }
            if ($locked->total_amount < 0) {
                throw ValidationException::withMessages(['invoice' => '請求額が不正です。']);
            }

            $number = sprintf('INV-%s-%06d', $locked->billing_month->format('Ym'), $locked->id);
            $locked->update([
                'invoice_number' => $number,
                'status' => MonthlyInvoiceStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $actor->id,
            ]);
            MonthlyInvoiceAudit::query()->create([
                'monthly_invoice_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'event' => 'confirmed',
                'after_values' => ['invoice_number' => $number, 'total_amount' => $locked->total_amount],
                'metadata' => ['warnings_acknowledged' => $acknowledgeWarnings],
                'created_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);

        $this->notifications->monthlyInvoiceConfirmed($confirmed);

        return $confirmed;
    }
}
