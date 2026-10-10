<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\InvoiceItemType;
use App\Enums\MonthlyInvoiceStatus;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\MonthlyInvoiceTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddInvoiceAdjustment
{
    public function __construct(private readonly MonthlyInvoiceTotals $totals) {}

    public function handle(MonthlyInvoice $invoice, User $actor, int $amount, string $reason, string $description = '調整', ?int $transferRequestId = null): MonthlyInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $amount, $reason, $description, $transferRequestId): MonthlyInvoice {
            $locked = MonthlyInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== MonthlyInvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => '確定済み請求の金額は変更できません。取消履歴を残して対応してください。']);
            }
            if ($transferRequestId !== null) {
                $transfer = TransferRequest::query()->where('student_profile_id', $locked->student_profile_id)
                    ->where('status', ApplicationStatus::Approved)->whereKey($transferRequestId)
                    ->where('terms_snapshot->billing_month', $locked->billing_month->toDateString())->first();
                if ($transfer === null || ! ($transfer->terms_snapshot['requires_review'] ?? false)) {
                    throw ValidationException::withMessages(['transfer_request_id' => 'この請求の未確定振替費用を指定してください。']);
                }
                if ($locked->items()->where('is_manual', true)->where('source_type', TransferRequest::class)->where('source_id', $transferRequestId)->exists()) {
                    throw ValidationException::withMessages(['transfer_request_id' => 'この振替費用の確認明細は登録済みです。']);
                }
            }
            if ($amount === 0 && $transferRequestId === null) {
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
                'source_type' => $transferRequestId === null ? null : TransferRequest::class,
                'source_id' => $transferRequestId,
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
