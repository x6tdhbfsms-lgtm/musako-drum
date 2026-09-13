<?php

namespace App\Models;

use App\Enums\InvoiceItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'monthly_invoice_id', 'type', 'description', 'quantity', 'unit_amount', 'amount', 'is_manual',
        'source_type', 'source_id', 'pricing_snapshot', 'reason', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['type' => InvoiceItemType::class, 'is_manual' => 'boolean', 'pricing_snapshot' => 'array'];
    }

    public function monthlyInvoice(): BelongsTo
    {
        return $this->belongsTo(MonthlyInvoice::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
