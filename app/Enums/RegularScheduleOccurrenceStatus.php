<?php

namespace App\Enums;

enum RegularScheduleOccurrenceStatus: string
{
    case Draft = 'draft';
    case Conflict = 'conflict';
    case Confirmed = 'confirmed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
