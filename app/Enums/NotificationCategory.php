<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Reservation = 'reservation';
    case Attendance = 'attendance';
    case Transfer = 'transfer';
    case Procedure = 'procedure';
    case Inquiry = 'inquiry';
    case Reminder = 'reminder';
    case Trial = 'trial';
    case Admission = 'admission';
}
