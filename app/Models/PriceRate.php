<?php

namespace App\Models;

use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use Database\Factories\PriceRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceRate extends Model
{
    /** @use HasFactory<PriceRateFactory> */
    use HasFactory;

    protected $fillable = [
        'kind', 'pricing_category', 'monthly_lesson_count', 'amount', 'effective_from', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PriceRateKind::class,
            'pricing_category' => PricingCategory::class,
            'amount' => 'integer',
            'effective_from' => 'date',
        ];
    }

    public function scopeEffectiveOn(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }
}
