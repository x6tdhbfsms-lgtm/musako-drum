<?php

namespace Tests\Feature;

use App\Enums\LessonType;
use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Models\PriceRate;
use App\Services\LessonPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonPricingServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('supportedPriceCases')]
    public function test_calculates_supported_lesson_and_studio_prices(
        LessonType $lessonType,
        PricingCategory $category,
        int $monthlyCount,
        int $expectedLessonFee,
        int $expectedMonthlyTotal,
    ): void {
        $this->seed();

        $quote = app(LessonPricingService::class)->quote(
            CarbonImmutable::parse('2026-09-13'),
            $lessonType,
            $monthlyCount,
            $category,
        );

        $this->assertSame($expectedLessonFee, $quote->lessonFeeTotal);
        $this->assertSame(1610, $quote->studioFeePerLesson);
        $this->assertSame(1610 * $monthlyCount, $quote->estimatedMonthlyStudioFee);
        $this->assertSame($expectedMonthlyTotal, $quote->estimatedMonthlyTotal);
        $this->assertFalse($quote->isConsultationRequired);
    }

    public function test_marks_a_monthly_count_without_a_rate_as_consultation_required(): void
    {
        $this->seed();

        $quote = app(LessonPricingService::class)->quote(
            CarbonImmutable::parse('2026-09-13'),
            LessonType::Flex,
            5,
            PricingCategory::Standard,
        );

        $this->assertNull($quote->baseLessonFee);
        $this->assertSame(500, $quote->flexSurcharge);
        $this->assertSame(8050, $quote->estimatedMonthlyStudioFee);
        $this->assertNull($quote->lessonFeeTotal);
        $this->assertNull($quote->estimatedMonthlyTotal);
        $this->assertTrue($quote->isConsultationRequired);
    }

    public function test_selects_the_rate_effective_on_the_target_date_without_changing_past_results(): void
    {
        $this->seed();
        PriceRate::factory()->create([
            'kind' => PriceRateKind::RegularLesson,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_count' => 2,
            'amount' => 12000,
            'effective_from' => '2026-10-01',
        ]);
        PriceRate::factory()->create([
            'kind' => PriceRateKind::RegularLesson,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_count' => 2,
            'amount' => 13000,
            'effective_from' => '2026-11-01',
        ]);
        $service = app(LessonPricingService::class);

        $past = $service->quote(CarbonImmutable::parse('2026-09-30'), LessonType::Regular, 2, PricingCategory::Standard);
        $current = $service->quote(CarbonImmutable::parse('2026-10-15'), LessonType::Regular, 2, PricingCategory::Standard);

        $this->assertSame(11000, $past->lessonFeeTotal);
        $this->assertSame(12000, $current->lessonFeeTotal);
    }

    /** @return array<string, array{LessonType, PricingCategory, int, int, int}> */
    public static function supportedPriceCases(): array
    {
        return [
            'regular_standard_1' => [LessonType::Regular, PricingCategory::Standard, 1, 6000, 7610],
            'regular_standard_2' => [LessonType::Regular, PricingCategory::Standard, 2, 11000, 14220],
            'regular_standard_3' => [LessonType::Regular, PricingCategory::Standard, 3, 16500, 21330],
            'regular_standard_4' => [LessonType::Regular, PricingCategory::Standard, 4, 22000, 28440],
            'regular_junior_1' => [LessonType::Regular, PricingCategory::Junior, 1, 5500, 7110],
            'regular_junior_2' => [LessonType::Regular, PricingCategory::Junior, 2, 10000, 13220],
            'regular_junior_3' => [LessonType::Regular, PricingCategory::Junior, 3, 15000, 19830],
            'regular_junior_4' => [LessonType::Regular, PricingCategory::Junior, 4, 20000, 26440],
            'flex_standard_1' => [LessonType::Flex, PricingCategory::Standard, 1, 6500, 8110],
            'flex_standard_2' => [LessonType::Flex, PricingCategory::Standard, 2, 11500, 14720],
            'flex_standard_3' => [LessonType::Flex, PricingCategory::Standard, 3, 17000, 21830],
            'flex_standard_4' => [LessonType::Flex, PricingCategory::Standard, 4, 22500, 28940],
            'flex_junior_1' => [LessonType::Flex, PricingCategory::Junior, 1, 6000, 7610],
            'flex_junior_2' => [LessonType::Flex, PricingCategory::Junior, 2, 10500, 13720],
            'flex_junior_3' => [LessonType::Flex, PricingCategory::Junior, 3, 15500, 20330],
            'flex_junior_4' => [LessonType::Flex, PricingCategory::Junior, 4, 20500, 26940],
        ];
    }
}
