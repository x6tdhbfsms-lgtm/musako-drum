<?php

namespace App\Enums;

enum InvoiceItemType: string
{
    case LessonFee = 'lesson_fee';
    case FlexSurcharge = 'flex_surcharge';
    case StudioFee = 'studio_fee';
    case Adjustment = 'adjustment';
    case Other = 'other';
}
