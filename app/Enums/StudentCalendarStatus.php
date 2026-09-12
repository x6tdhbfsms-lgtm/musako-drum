<?php

namespace App\Enums;

enum StudentCalendarStatus: string
{
    case Available = 'available';
    case ReservationPending = 'reservation_pending';
    case ReservationApproved = 'reservation_approved';
    case TransferPending = 'transfer_pending';
    case TransferApproved = 'transfer_approved';
    case Absence = 'absence';
    case Late = 'late';
    case Full = 'full';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => '予約可能',
            self::ReservationPending => '予約申請中',
            self::ReservationApproved => '予約確定',
            self::TransferPending => '振替申請中',
            self::TransferApproved => '振替確定',
            self::Absence => 'お休み連絡済み',
            self::Late => '遅刻連絡済み',
            self::Full => '満席',
            self::Unavailable => '休講／予約不可',
        };
    }
}
