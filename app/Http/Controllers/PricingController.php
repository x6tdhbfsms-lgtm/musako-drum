<?php

namespace App\Http\Controllers;

use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Models\PricingSetting;
use App\Services\LessonPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

class PricingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(LessonPricingService $pricingService): View
    {
        $date = CarbonImmutable::today(config('app.timezone'));
        $setting = PricingSetting::query()->effectiveOn($date)->first();
        $quotes = collect(PricingCategory::cases())->mapWithKeys(
            fn (PricingCategory $category): array => [
                $category->value => collect(range(1, 4))->mapWithKeys(
                    fn (int $count): array => [
                        $count => $pricingService->quote($date, LessonType::Regular, $count, $category),
                    ],
                ),
            ],
        );
        $flexQuote = $pricingService->quote($date, LessonType::Flex, 1, PricingCategory::Standard);

        return view('pricing', compact('setting', 'quotes', 'flexQuote'));
    }
}
