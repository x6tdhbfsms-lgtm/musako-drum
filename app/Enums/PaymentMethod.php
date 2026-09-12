<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CreditCard = 'credit_card';
    case BankTransfer = 'bank_transfer';
    case DirectDebit = 'direct_debit';
    case Cash = 'cash';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CreditCard => 'クレジットカード',
            self::BankTransfer => '銀行振込',
            self::DirectDebit => '口座振替',
            self::Cash => '現金',
            self::Other => 'その他',
        };
    }
}
