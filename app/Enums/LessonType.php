<?php

namespace App\Enums;

enum LessonType: string
{
    case Regular = 'regular';
    case Flex = 'flex';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'レギュラーレッスン',
            self::Flex => 'フレックスレッスン',
        };
    }
}
