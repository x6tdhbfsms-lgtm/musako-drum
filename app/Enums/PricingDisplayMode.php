<?php

namespace App\Enums;

enum PricingDisplayMode: string
{
    case CurrentPrices = 'current_prices';
    case ExamplePrices = 'example_prices';
    case ExternalOnly = 'external_only';

    public function label(): string
    {
        return match ($this) {
            self::CurrentPrices => '現在の料金目安',
            self::ExamplePrices => '料金例',
            self::ExternalOnly => '公式ページのみ案内',
        };
    }
}
