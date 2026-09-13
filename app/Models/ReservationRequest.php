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
        'lesson_entitlement_month', 'studio_fee_amount', 'studio_fee_priced_on', 'reviewed_by_user_id', 'reviewed_at', 'student_note', 'staff_note',
        'cancelled_at', 'cancellation_reason', 'completed_at', 'monthly_limit_overridden_at',
        'monthly_limit_overridden_by_user_id', 'monthly_limit_override_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'lesson_entitlement_month' => 'date',
            'studio_fee_amount' => 'integer',
            'studio_fee_priced_on' => 'date',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'monthly_limit_overridden_at' => 'datetime',
        ];
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

    public function monthlyLimitOverrideReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'monthly_limit_overridden_by_user_id');
    }

    public function attendanceNotice(): HasOne
    {
        return $this->hasOne(AttendanceNotice::class);
    }

    public function lessonReminderDelivery(): HasOne
    {
        return $this->hasOne(LessonReminderDelivery::class);
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
