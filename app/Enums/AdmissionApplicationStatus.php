<?php

namespace App\Enums;

enum AdmissionApplicationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '未処理',
            self::Approved => '承認済み',
            self::Rejected => '却下',
            self::Cancelled => 'キャンセル',
        };
    }
}
