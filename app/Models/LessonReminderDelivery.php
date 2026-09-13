<?php

namespace App\Models;

use Database\Factories\LessonReminderDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonReminderDelivery extends Model
{
    /** @use HasFactory<LessonReminderDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'reservation_request_id',
        'user_id',
        'lesson_on',
        'queued_at',
        'sent_at',
        'failed_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'lesson_on' => 'date',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function reservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
