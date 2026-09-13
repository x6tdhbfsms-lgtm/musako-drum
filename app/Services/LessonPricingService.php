<?php

namespace App\Services;

use App\Enums\LessonType;
use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Models\LessonEnrollment;
use App\Models\PriceRate;
use App\Support\LessonPriceQuote;
use Carbon\CarbonImmutable;

class LessonPricingService
{
    /** @var array<string, int|null> */
    private array $amountCache = [];

    public function forEnrollment(LessonEnrollment $enrollment, mixed $date): LessonPriceQuote
    {
        return $this->quote(
            CarbonImmutable::parse($date, config('app.timezone')),
            $enrollment->lesson_type,
            (int) $enrollment->monthly_lesson_limit,
            $enrollment->pricing_category,
        );
    }

    public function quote(
        CarbonImmutable $date,
        LessonType $lessonType,
        int $monthlyLessonCount,
        PricingCategory $pricingCategory,
    ): LessonPriceQuote {
        $base = $this->amountOn(
            PriceRateKind::RegularLesson,
            $date,
            $pricingCategory,
            $monthlyLessonCount,
        );
        $studioFee = $this->amountOn(PriceRateKind::StudioPerLesson, $date);
        $flexSurcharge = $lessonType === LessonType::Flex
            ? ($this->amountOn(PriceRateKind::FlexSurcharge, $date) ?? 0)
            : 0;
        $consultationRequired = $base === null;
        $lessonTotal = $consultationRequired ? null : $base + $flexSurcharge;
        $monthlyStudioFee = $studioFee === null ? null : $studioFee * $monthlyLessonCount;
        $monthlyTotal = $lessonTotal === null || $monthlyStudioFee === null
            ? null
            : $lessonTotal + $monthlyStudioFee;

        return new LessonPriceQuote(
            baseLessonFee: $base,
            flexSurcharge: $flexSurcharge,
            lessonFeeTotal: $lessonTotal,
            studioFeePerLesson: $studioFee,
            estimatedMonthlyStudioFee: $monthlyStudioFee,
            estimatedMonthlyTotal: $monthlyTotal,
            isConsultationRequired: $consultationRequired,
        );
    }

    private function amountOn(
        PriceRateKind $kind,
        CarbonImmutable $date,
        ?PricingCategory $category = null,
        ?int $monthlyLessonCount = null,
    ): ?int {
        $key = implode('|', [$kind->value, $category?->value ?? '-', $monthlyLessonCount ?? '-', $date->toDateString()]);

        return $this->amountCache[$key] ??= PriceRate::query()
            ->where('kind', $kind)
            ->when(
                $category === null,
                fn ($query) => $query->whereNull('pricing_category'),
                fn ($query) => $query->where('pricing_category', $category),
            )
            ->when(
                $monthlyLessonCount === null,
                fn ($query) => $query->whereNull('monthly_lesson_count'),
                fn ($query) => $query->where('monthly_lesson_count', $monthlyLessonCount),
            )
            ->effectiveOn($date)
            ->value('amount');
    }
}
