<?php

namespace App\Actions;

use App\Enums\InvoicePaymentStatus;
use App\Enums\MonthlyInvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\PaymentRecord;
use App\Models\User;
use App\Services\MonthlyInvoiceTotals;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterInvoicePayment
{
    public function __construct(
        private readonly MonthlyInvoiceTotals $totals,
        private readonly MusakoNotificationService $notifications,
    ) {}

    public function handle(MonthlyInvoice $invoice, User $actor, array $data): PaymentRecord
    {
        [$payment, $becamePaid] = DB::transaction(function () use ($invoice, $actor, $data): array {
            $locked = MonthlyInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== MonthlyInvoiceStatus::Confirmed) {
                throw ValidationException::withMessages(['invoice' => '確定済み請求にのみ入金を登録できます。']);
            }
            $paid = (int) $locked->paymentRecords()->lockForUpdate()->get()->sum('amount');
            $amount = (int) $data['amount'];
            if ($amount <= 0 || $paid + $amount > $locked->total_amount) {
                throw ValidationException::withMessages(['amount' => '入金額が残額を超えています。']);
            }
            $wasPaid = $locked->payment_status === InvoicePaymentStatus::Paid;
            $payment = $locked->paymentRecords()->create([
                'amount' => $amount,
                'paid_on' => $data['paid_on'],
                'payment_method' => PaymentMethod::from($data['payment_method']),
                'external_payment_provider' => $data['external_payment_provider'] ?? null,
                'external_payment_reference' => $data['external_payment_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
            $updated = $this->totals->recalculate($locked);
            MonthlyInvoiceAudit::query()->create([
                'monthly_invoice_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'event' => 'payment_registered',
                'after_values' => ['payment_record_id' => $payment->id, 'amount' => $amount, 'paid_amount' => $updated->paid_amount],
                'created_at' => now(),
            ]);

            return [$payment, ! $wasPaid && $updated->payment_status === InvoicePaymentStatus::Paid];
        }, 3);

        if ($becamePaid) {
            $this->notifications->monthlyInvoicePaid($payment->monthlyInvoice()->firstOrFail());
        }

        return $payment;
    }
}
