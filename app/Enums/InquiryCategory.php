<?php

namespace App\Enums;

enum InquiryCategory: string
{
    case Lesson = 'lesson';
    case Reservation = 'reservation';
    case Pricing = 'pricing';
    case Contract = 'contract';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lesson => 'レッスン',
            self::Reservation => '予約',
            self::Pricing => '料金',
            self::Contract => '契約',
            self::Other => 'その他',
        };
    }
}
