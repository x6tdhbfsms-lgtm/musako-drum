<?php

namespace App\Enums;

enum RegularScheduleBatchStatus: string
{
    case Draft = 'draft';
    case Conflict = 'conflict';
    case Confirmed = 'confirmed';
    case Skipped = 'skipped';
}
