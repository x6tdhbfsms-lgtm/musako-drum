<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRecord extends Model
{
    protected $fillable = [
        'monthly_invoice_id', 'idempotency_key', 'amount', 'paid_on', 'payment_method', 'external_payment_provider',
        'external_payment_reference', 'notes', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date', 'payment_method' => PaymentMethod::class];
    }

    public function monthlyInvoice(): BelongsTo
    {
        return $this->belongsTo(MonthlyInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
