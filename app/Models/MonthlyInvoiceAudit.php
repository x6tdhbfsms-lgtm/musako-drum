<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyInvoiceAudit extends Model
{
    public $timestamps = false;

    protected $fillable = ['monthly_invoice_id', 'actor_user_id', 'event', 'before_values', 'after_values', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function monthlyInvoice(): BelongsTo
    {
        return $this->belongsTo(MonthlyInvoice::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
