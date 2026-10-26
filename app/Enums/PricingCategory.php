<?php

namespace App\Enums;

enum PricingCategory: string
{
    case Standard = 'standard';
    case Junior = 'junior';
    case Kids = 'kids';

    public function label(): string
    {
        return match ($this) {
            self::Standard => '一般',
            self::Junior => 'ジュニア',
            self::Kids => 'キッズ（3〜7歳）',
        };
    }
}
