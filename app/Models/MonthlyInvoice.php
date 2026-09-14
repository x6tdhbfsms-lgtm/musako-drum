<?php

namespace App\Models;

use App\Enums\InvoicePaymentStatus;
use App\Enums\MonthlyInvoiceStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonthlyInvoice extends Model
{
    protected $fillable = [
        'student_profile_id', 'billing_month', 'invoice_number', 'status', 'payment_status', 'payment_method',
        'external_payment_provider', 'external_payment_reference', 'due_on', 'pricing_snapshot', 'contract_snapshot',
        'warnings', 'requires_review', 'has_manual_adjustments', 'base_lesson_fee', 'flex_surcharge', 'lesson_fee_total',
        'studio_fee_total', 'subtotal', 'adjustments_total', 'total_amount', 'paid_amount', 'generated_at',
        'generated_by_user_id', 'confirmed_at', 'confirmed_by_user_id', 'cancelled_at', 'cancelled_by_user_id',
        'cancellation_reason', 'reissued_from_invoice_id', 'reissued_at', 'reissued_by_user_id', 'reissue_reason',
    ];

    protected function casts(): array
    {
        return [
            'billing_month' => 'date',
            'status' => MonthlyInvoiceStatus::class,
            'payment_status' => InvoicePaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'due_on' => 'date',
            'pricing_snapshot' => 'array',
            'contract_snapshot' => 'array',
            'warnings' => 'array',
            'requires_review' => 'boolean',
            'has_manual_adjustments' => 'boolean',
            'generated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reissued_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function reissuedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissued_from_invoice_id');
    }

    public function reissues(): HasMany
    {
        return $this->hasMany(self::class, 'reissued_from_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function paymentRecords(): HasMany
    {
        return $this->hasMany(PaymentRecord::class)->orderBy('paid_on')->orderBy('id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(MonthlyInvoiceAudit::class)->latest('created_at');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function getRemainingAmountAttribute(): int
    {
        if ($this->status === MonthlyInvoiceStatus::Cancelled) {
            return 0;
        }

        return max(0, (int) $this->total_amount - (int) $this->paid_amount);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === MonthlyInvoiceStatus::Confirmed
            && $this->payment_status !== InvoicePaymentStatus::Paid
            && $this->due_on?->isPast();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', MonthlyInvoiceStatus::Confirmed);
    }
}
