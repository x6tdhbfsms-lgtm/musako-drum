<?php

namespace App\Enums;

enum MonthlyInvoiceStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Confirmed => '確定済み',
            self::Cancelled => '取消済み',
        };
    }
}
