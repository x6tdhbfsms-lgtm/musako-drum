<?php

namespace Database\Factories;

use App\Enums\PricingDisplayMode;
use App\Models\PricingSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingSetting>
 */
class PricingSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_from' => now()->startOfMonth(),
            'display_mode' => PricingDisplayMode::CurrentPrices,
            'normal_admission_fee' => null,
            'admission_campaign_enabled' => true,
            'admission_campaign_fee' => 0,
            'admission_campaign_message' => '入会金無料キャンペーン中',
            'pricing_notice' => '料金は変更になる場合があります。最新の料金・詳細については公式ホームページをご確認ください。',
        ];
    }
}
