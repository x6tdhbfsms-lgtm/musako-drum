<?php

namespace App\Enums;

enum PricingCategory: string
{
    case Standard = 'standard';
    case Junior = 'junior';

    public function label(): string
    {
        return match ($this) {
            self::Standard => '一般',
            self::Junior => 'ジュニア',
        };
    }
}
