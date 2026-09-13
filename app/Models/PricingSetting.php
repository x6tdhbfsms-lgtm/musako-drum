<?php

namespace App\Models;

use App\Enums\PricingDisplayMode;
use Database\Factories\PricingSettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    /** @use HasFactory<PricingSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'effective_from', 'display_mode', 'normal_admission_fee', 'admission_campaign_enabled',
        'admission_campaign_fee', 'admission_campaign_message', 'pricing_notice',
        'lesson_pricing_url', 'studio_pricing_url', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'display_mode' => PricingDisplayMode::class,
            'normal_admission_fee' => 'integer',
            'admission_campaign_enabled' => 'boolean',
            'admission_campaign_fee' => 'integer',
        ];
    }

    public function scopeEffectiveOn(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }
}
