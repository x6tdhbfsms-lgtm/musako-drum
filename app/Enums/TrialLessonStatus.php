<?php

namespace App\Enums;

enum TrialLessonStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '未処理',
            self::Approved => '承認済み',
            self::Rejected => '却下',
            self::Cancelled => 'キャンセル',
            self::Completed => '体験完了',
            self::NoShow => '欠席',
            self::Converted => '入会済み',
        };
    }

    public function reservesCapacity(): bool
    {
        return in_array($this, [self::Pending, self::Approved], true);
    }
}
