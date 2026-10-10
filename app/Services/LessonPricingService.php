<?php

namespace App\Services;

use App\Enums\LessonType;
use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\PriceRate;
use App\Support\LessonPriceQuote;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class LessonPricingService
{
    /** @var array<string, int|null> */
    private array $amountCache = [];

    public function requiredStudioFeeForSlot(LessonSlot $slot): int
    {
        return match ($slot->venue?->termsRoomType()) {
            'studio34' => $this->requiredStudioFee($slot->starts_at),
            'music_room' => 1000,
            default => $this->requiredStudioFee($slot->starts_at),
        };
    }

    public function requiredStudioFee(mixed $date): int
    {
        $amount = $this->amountOn(PriceRateKind::StudioPerLesson, CarbonImmutable::parse($date, config('app.timezone')));
        if ($amount === null) {
            throw ValidationException::withMessages([
                'studio_fee' => 'レッスン日に適用されるスタジオ料金が未設定です。管理者が料金履歴を確認してから再度承認してください。',
            ]);
        }

        return $amount;
    }

    public function forEnrollment(LessonEnrollment $enrollment, mixed $date): LessonPriceQuote
    {
        if ($enrollment->venue?->termsRoomType() === 'music_room'
            || str_contains($enrollment->course?->code ?? '', 'ELECTRONIC-')) {
            return $this->electronicQuote((int) $enrollment->lesson_minutes, (int) $enrollment->monthly_lesson_limit);
        }

        return $this->quote(
            CarbonImmutable::parse($date, config('app.timezone')),
            $enrollment->lesson_type,
            (int) $enrollment->monthly_lesson_limit,
            $enrollment->pricing_category,
            $enrollment->venue?->termsRoomType() === 'music_room' ? 1000 : null,
        );
    }

    public function electronicQuote(int $minutes, int $monthlyLessonCount): LessonPriceQuote
    {
        $unit = config('musako.electronic_drum.lesson_fees.'.$minutes);
        $base = is_int($unit) && $monthlyLessonCount > 0 ? $unit * $monthlyLessonCount : null;
        $room = (int) config('musako.electronic_drum.room_fee', 1000);

        return new LessonPriceQuote(
            baseLessonFee: $base,
            flexSurcharge: 0,
            lessonFeeTotal: $base,
            studioFeePerLesson: $room,
            estimatedMonthlyStudioFee: $base === null ? null : $room * $monthlyLessonCount,
            estimatedMonthlyTotal: $base === null ? null : $base + $room * $monthlyLessonCount,
            isConsultationRequired: $base === null,
        );
    }

    public function quote(
        CarbonImmutable $date,
        LessonType $lessonType,
        int $monthlyLessonCount,
        PricingCategory $pricingCategory,
        ?int $studioFeeOverride = null,
    ): LessonPriceQuote {
        $base = $this->amountOn(
            PriceRateKind::RegularLesson,
            $date,
            $pricingCategory,
            $monthlyLessonCount,
        );
        $studioFee = $studioFeeOverride ?? $this->amountOn(PriceRateKind::StudioPerLesson, $date);
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
