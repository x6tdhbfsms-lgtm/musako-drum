<?php

namespace App\Models;

use App\Enums\AttendanceNoticeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceNotice extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_request_id',
        'type',
        'late_minutes',
        'expected_arrival_at',
        'notes',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttendanceNoticeType::class,
            'expected_arrival_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function reservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class);
    }
}
