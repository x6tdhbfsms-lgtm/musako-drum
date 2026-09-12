<?php

namespace App\Enums;

enum LessonSlotStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
