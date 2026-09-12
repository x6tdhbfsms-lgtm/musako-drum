<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id', 'lesson_slot_id', 'lesson_enrollment_id', 'status', 'requested_at',
        'reviewed_by_user_id', 'reviewed_at', 'student_note', 'staff_note', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return ['status' => ReservationStatus::class, 'requested_at' => 'datetime', 'reviewed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function lessonSlot(): BelongsTo
    {
        return $this->belongsTo(LessonSlot::class);
    }

    public function lessonEnrollment(): BelongsTo
    {
        return $this->belongsTo(LessonEnrollment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
