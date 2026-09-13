<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\PaymentMethod;
use App\Enums\PricingCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id', 'course_id', 'lesson_type', 'pricing_category', 'teacher_profile_id', 'venue_id', 'weekday', 'starts_at_time',
        'monthly_lesson_limit', 'lesson_minutes', 'payment_method', 'status', 'starts_on', 'ends_on',
        'supersedes_lesson_enrollment_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'lesson_type' => LessonType::class,
            'pricing_category' => PricingCategory::class,
            'payment_method' => PaymentMethod::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function supersededEnrollment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_lesson_enrollment_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_lesson_enrollment_id');
    }

    public function contractChangeRequests(): HasMany
    {
        return $this->hasMany(ContractChangeRequest::class);
    }

    public function scopeActiveOn(Builder $query, mixed $date): Builder
    {
        return $query->where('status', EnrollmentStatus::Active)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }
}
