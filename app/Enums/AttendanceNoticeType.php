<?php

namespace App\Enums;

enum AttendanceNoticeType: string
{
    case Absence = 'absence';
    case Late = 'late';
}
