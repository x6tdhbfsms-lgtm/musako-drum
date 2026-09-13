<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSetting extends Model
{
    protected $fillable = [
        'singleton_key', 'auto_generate_enabled', 'generation_day', 'generation_time', 'due_rule', 'due_day',
        'invoice_notifications_enabled', 'payment_notifications_enabled', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'auto_generate_enabled' => 'boolean',
            'generation_day' => 'integer',
            'invoice_notifications_enabled' => 'boolean',
            'payment_notifications_enabled' => 'boolean',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['singleton_key' => 'default'], [
            'auto_generate_enabled' => true,
            'generation_day' => 25,
            'generation_time' => '02:00',
            'due_rule' => 'previous_month_end',
            'invoice_notifications_enabled' => true,
            'payment_notifications_enabled' => true,
        ]);
    }

    public function dueDateFor(CarbonImmutable $billingMonth): CarbonImmutable
    {
        if ($this->due_rule === 'billing_month_day') {
            return $billingMonth->day(min((int) ($this->due_day ?: 10), $billingMonth->daysInMonth));
        }

        return $billingMonth->subMonth()->endOfMonth();
    }
}
