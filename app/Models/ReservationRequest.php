<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function attendanceNotice(): HasOne
    {
        return $this->hasOne(AttendanceNotice::class);
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(TransferRequest::class, 'original_reservation_request_id');
    }

    public function resultingTransferRequest(): HasOne
    {
        return $this->hasOne(TransferRequest::class, 'resulting_reservation_request_id');
    }

    public function transferRequestDeadline(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->lessonSlot->starts_at)
            ->setTimezone(config('app.timezone'))
            ->subDay()
            ->setTime(19, 0);
    }
}
