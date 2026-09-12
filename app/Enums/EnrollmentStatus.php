<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
