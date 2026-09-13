<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceNotificationDelivery extends Model
{
    protected $fillable = ['monthly_invoice_id', 'type', 'signature', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function monthlyInvoice(): BelongsTo
    {
        return $this->belongsTo(MonthlyInvoice::class);
    }
}
