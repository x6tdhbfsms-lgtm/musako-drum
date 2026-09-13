<?php

namespace App\Enums;

enum LessonSlotAudience: string
{
    case Regular = 'regular';
    case Trial = 'trial';
    case Both = 'both';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Regular => '通常予約のみ',
            self::Trial => '体験予約のみ',
            self::Both => '通常・体験の両方',
            self::Hidden => '非公開',
        };
    }

    public function acceptsRegular(): bool
    {
        return in_array($this, [self::Regular, self::Both], true);
    }

    public function acceptsTrial(): bool
    {
        return in_array($this, [self::Trial, self::Both], true);
    }
}
