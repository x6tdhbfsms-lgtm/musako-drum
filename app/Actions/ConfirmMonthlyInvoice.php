<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\MonthlyInvoiceStatus;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\TransferRequest;
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
            $unresolvedTransfers = TransferRequest::query()
                ->where('student_profile_id', $locked->student_profile_id)->where('status', ApplicationStatus::Approved)
                ->where('terms_snapshot->billing_month', $locked->billing_month->copy()->startOfMonth()->toDateString())
                ->whereNotNull('terms_snapshot')->get();
            foreach ($unresolvedTransfers as $transfer) {
                if (($transfer->terms_snapshot['requires_review'] ?? true) && ! $locked->items()->where('is_manual', true)
                    ->where('source_type', TransferRequest::class)->where('source_id', $transfer->id)->exists()) {
                    throw ValidationException::withMessages(['invoice' => '振替#'.$transfer->id.'の追加料金が未確定です。会場区分と料金の確認が必要です。']);
                }
                foreach ($transfer->terms_snapshot['components'] ?? [] as $component => $amount) {
                    if ($amount !== null && $amount > 0 && ! $locked->items()
                        ->where('source_type', TransferRequest::class)->where('source_id', $transfer->id)
                        ->where('is_manual', false)->where('pricing_snapshot->component', $component)
                        ->where('amount', $amount)->exists()) {
                        throw ValidationException::withMessages(['invoice' => '振替の追加料金が明細に反映されていません。下書きを再生成して確認してください。']);
                    }
                }
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
