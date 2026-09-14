<?php

namespace App\Actions;

use App\Enums\MonthlyInvoiceStatus;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\MonthlyInvoiceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReissueMonthlyInvoice
{
    public function __construct(private readonly MonthlyInvoiceGenerator $generator) {}

    public function handle(MonthlyInvoice $invoice, User $actor, string $reason): MonthlyInvoice
    {
        Gate::forUser($actor)->authorize('manage', MonthlyInvoice::class);
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => '再発行理由を2000文字以内で入力してください。']);
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): MonthlyInvoice {
            $student = StudentProfile::query()->lockForUpdate()->findOrFail($invoice->student_profile_id);
            $original = MonthlyInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($original->status !== MonthlyInvoiceStatus::Cancelled || $original->paymentRecords()->exists()) {
                throw ValidationException::withMessages(['invoice' => '入金のない取消済み請求だけ再発行できます。']);
            }
            $existing = $original->reissues()->first();
            if ($existing !== null) {
                return $existing;
            }
            if (MonthlyInvoice::query()->whereBelongsTo($student)->whereDate('billing_month', $original->billing_month)
                ->where('status', '!=', MonthlyInvoiceStatus::Cancelled)->exists()) {
                throw ValidationException::withMessages(['invoice' => '同じ対象月の有効な請求がすでにあります。']);
            }

            $draft = MonthlyInvoice::query()->create([
                'student_profile_id' => $student->id,
                'billing_month' => $original->billing_month,
                'status' => MonthlyInvoiceStatus::Draft,
                'generated_at' => now(),
                'generated_by_user_id' => $actor->id,
                'reissued_from_invoice_id' => $original->id,
                'reissued_at' => now(),
                'reissued_by_user_id' => $actor->id,
                'reissue_reason' => trim($reason),
            ]);
            $generated = $this->generator->generateForStudent($student, CarbonImmutable::instance($original->billing_month), $actor);
            if ($generated === null || $generated->id !== $draft->id) {
                throw ValidationException::withMessages(['invoice' => '対象月の契約が見つからないため再発行できません。管理者が契約履歴を確認してください。']);
            }
            MonthlyInvoiceAudit::query()->create([
                'monthly_invoice_id' => $draft->id,
                'actor_user_id' => $actor->id,
                'event' => 'reissued',
                'after_values' => ['reissued_from_invoice_id' => $original->id, 'reason' => trim($reason)],
                'created_at' => now(),
            ]);

            return $generated;
        }, 3);
    }
}
