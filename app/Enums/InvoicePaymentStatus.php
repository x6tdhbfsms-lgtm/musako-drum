<?php

namespace App\Enums;

enum InvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => '未入金',
            self::PartiallyPaid => '一部入金',
            self::Paid => '入金済み',
        };
    }
}
