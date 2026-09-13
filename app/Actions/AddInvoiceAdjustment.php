<?php

namespace App\Actions;

use App\Enums\InvoiceItemType;
use App\Enums\MonthlyInvoiceStatus;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\User;
use App\Services\MonthlyInvoiceTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddInvoiceAdjustment
{
    public function __construct(private readonly MonthlyInvoiceTotals $totals) {}

    public function handle(MonthlyInvoice $invoice, User $actor, int $amount, string $reason, string $description = '調整'): MonthlyInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $amount, $reason, $description): MonthlyInvoice {
            $locked = MonthlyInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== MonthlyInvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => '確定済み請求の金額は変更できません。取消履歴を残して対応してください。']);
            }
            if ($amount === 0) {
                throw ValidationException::withMessages(['amount' => '0円の調整は登録できません。']);
            }
            if ($locked->total_amount + $amount < 0) {
                throw ValidationException::withMessages(['amount' => '請求合計が0円未満になる調整は登録できません。']);
            }

            $item = $locked->items()->create([
                'type' => InvoiceItemType::Adjustment,
                'description' => $description,
                'unit_amount' => $amount,
                'amount' => $amount,
                'is_manual' => true,
                'reason' => $reason,
                'created_by_user_id' => $actor->id,
            ]);
            $locked->update(['has_manual_adjustments' => true]);
            $updated = $this->totals->recalculate($locked);
            MonthlyInvoiceAudit::query()->create([
                'monthly_invoice_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'event' => 'adjustment_added',
                'after_values' => ['invoice_item_id' => $item->id, 'amount' => $amount, 'reason' => $reason],
                'created_at' => now(),
            ]);

            return $updated;
        }, 3);
    }
}
