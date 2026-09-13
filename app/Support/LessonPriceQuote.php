<?php

namespace App\Support;

class LessonPriceQuote
{
    public function __construct(
        public readonly ?int $baseLessonFee,
        public readonly int $flexSurcharge,
        public readonly ?int $lessonFeeTotal,
        public readonly ?int $studioFeePerLesson,
        public readonly ?int $estimatedMonthlyStudioFee,
        public readonly ?int $estimatedMonthlyTotal,
        public readonly bool $isConsultationRequired,
    ) {}
}
