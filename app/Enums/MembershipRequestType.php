<?php

namespace App\Enums;

enum MembershipRequestType: string
{
    case Pause = 'pause';
    case Withdraw = 'withdraw';
    case Resume = 'resume';
}
