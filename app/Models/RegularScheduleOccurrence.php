<?php

namespace App\Models;

use App\Enums\RegularScheduleOccurrenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RegularScheduleOccurrence extends Model
{
    protected $fillable = [
        'regular_schedule_batch_id', 'lesson_enrollment_id', 'student_profile_id', 'teacher_profile_id',
        'venue_id', 'course_id', 'source_key', 'week_number', 'starts_at', 'ends_at', 'lesson_entitlement_month',
        'status', 'conflict_reasons', 'manually_adjusted', 'confirmed_by_user_id', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lesson_entitlement_month' => 'date',
            'status' => RegularScheduleOccurrenceStatus::class,
            'conflict_reasons' => 'array',
            'manually_adjusted' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RegularScheduleBatch::class, 'regular_schedule_batch_id');
    }

    public function lessonEnrollment(): BelongsTo
    {
        return $this->belongsTo(LessonEnrollment::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function reservationRequest(): HasOne
    {
        return $this->hasOne(ReservationRequest::class);
    }
}
