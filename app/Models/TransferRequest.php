<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'original_reservation_request_id',
        'requested_lesson_slot_id',
        'resulting_reservation_request_id',
        'status',
        'reason',
        'student_note',
        'staff_note',
        'requested_at',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function originalReservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class, 'original_reservation_request_id');
    }

    public function requestedLessonSlot(): BelongsTo
    {
        return $this->belongsTo(LessonSlot::class, 'requested_lesson_slot_id');
    }

    public function resultingReservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class, 'resulting_reservation_request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
