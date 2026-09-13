<?php

namespace App\Enums;

enum PriceRateKind: string
{
    case RegularLesson = 'regular_lesson';
    case FlexSurcharge = 'flex_surcharge';
    case StudioPerLesson = 'studio_per_lesson';

    public function label(): string
    {
        return match ($this) {
            self::RegularLesson => 'レギュラー料金',
            self::FlexSurcharge => 'フレックス加算料金',
            self::StudioPerLesson => 'スタジオ使用料（1回）',
        };
    }
}
