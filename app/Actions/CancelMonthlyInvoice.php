<?php

namespace App\Actions;

use App\Enums\MonthlyInvoiceStatus;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelMonthlyInvoice
{
    public function handle(MonthlyInvoice $invoice, User $actor, string $reason): MonthlyInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason): MonthlyInvoice {
            $locked = MonthlyInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== MonthlyInvoiceStatus::Confirmed || $locked->paymentRecords()->exists()) {
                throw ValidationException::withMessages(['invoice' => '入金済み、または確定状態ではない請求は取り消せません。']);
            }
            $before = ['status' => $locked->status->value, 'total_amount' => $locked->total_amount];
            $locked->update([
                'status' => MonthlyInvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
            ]);
            MonthlyInvoiceAudit::query()->create([
                'monthly_invoice_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'event' => 'cancelled',
                'before_values' => $before,
                'after_values' => ['status' => MonthlyInvoiceStatus::Cancelled->value, 'reason' => $reason],
                'created_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }
}
