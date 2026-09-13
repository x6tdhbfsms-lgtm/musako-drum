<?php

namespace Database\Seeders;

use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Enums\PricingDisplayMode;
use App\Models\Course;
use App\Models\PriceRate;
use App\Models\PricingSetting;
use App\Models\Venue;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Course::query()->updateOrCreate(['code' => 'INDIVIDUAL-60'], [
            'name' => '個人レッスン60分',
            'lesson_type' => 'individual',
            'default_lesson_minutes' => 60,
            'default_monthly_lessons' => 2,
            'default_capacity' => 1,
        ]);

        Venue::query()->firstOrCreate(['code' => 'MUSAKO'], [
            'name' => 'MUSAKOドラム教室',
            'timezone' => 'Asia/Tokyo',
        ]);

        $effectiveFrom = '2026-09-01';
        foreach ([
            [PricingCategory::Standard, 1, 6000],
            [PricingCategory::Standard, 2, 11000],
            [PricingCategory::Standard, 3, 16500],
            [PricingCategory::Standard, 4, 22000],
            [PricingCategory::Junior, 1, 5500],
            [PricingCategory::Junior, 2, 10000],
            [PricingCategory::Junior, 3, 15000],
            [PricingCategory::Junior, 4, 20000],
        ] as [$category, $monthlyCount, $amount]) {
            PriceRate::query()->firstOrCreate([
                'kind' => PriceRateKind::RegularLesson,
                'pricing_category' => $category,
                'monthly_lesson_count' => $monthlyCount,
                'effective_from' => $effectiveFrom,
            ], ['amount' => $amount]);
        }

        PriceRate::query()->firstOrCreate([
            'kind' => PriceRateKind::FlexSurcharge,
            'effective_from' => $effectiveFrom,
        ], ['amount' => 500]);
        PriceRate::query()->firstOrCreate([
            'kind' => PriceRateKind::StudioPerLesson,
            'effective_from' => $effectiveFrom,
        ], ['amount' => 1610]);

        PricingSetting::query()->firstOrCreate(['effective_from' => $effectiveFrom], [
            'display_mode' => PricingDisplayMode::CurrentPrices,
            'normal_admission_fee' => null,
            'admission_campaign_enabled' => true,
            'admission_campaign_fee' => 0,
            'admission_campaign_message' => '入会金無料キャンペーン中',
            'pricing_notice' => '料金は変更になる場合があります。最新の料金・詳細については公式ホームページをご確認ください。',
        ]);
    }
}
