<?php

namespace Database\Factories;

use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Models\PriceRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceRate>
 */
class PriceRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => PriceRateKind::RegularLesson,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_count' => 2,
            'amount' => 11000,
            'effective_from' => now()->startOfMonth(),
        ];
    }
}
