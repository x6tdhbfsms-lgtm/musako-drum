<?php

namespace App\Models;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use Database\Factories\AdmissionApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionApplication extends Model
{
    /** @use HasFactory<AdmissionApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'public_reference', 'access_token_hash', 'pending_email_key', 'trial_lesson_request_id', 'name', 'name_kana',
        'email', 'email_normalized', 'phone', 'postal_code', 'address', 'course_id', 'lesson_type', 'pricing_category',
        'monthly_lesson_count', 'lesson_minutes', 'venue_id', 'teacher_profile_id', 'preferred_start_date', 'weekday',
        'starts_at_time', 'notes', 'status', 'privacy_policy_version', 'privacy_consented_at', 'requested_at',
        'processed_by_user_id', 'processed_at', 'rejection_reason', 'converted_user_id', 'converted_student_profile_id',
        'converted_lesson_enrollment_id',
    ];

    protected $hidden = ['access_token_hash', 'pending_email_key', 'email_normalized'];

    protected function casts(): array
    {
        return [
            'status' => AdmissionApplicationStatus::class,
            'lesson_type' => LessonType::class,
            'pricing_category' => PricingCategory::class,
            'preferred_start_date' => 'date',
            'privacy_consented_at' => 'datetime',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function trialLessonRequest(): BelongsTo
    {
        return $this->belongsTo(TrialLessonRequest::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    public function convertedStudentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'converted_student_profile_id');
    }

    public function convertedLessonEnrollment(): BelongsTo
    {
        return $this->belongsTo(LessonEnrollment::class, 'converted_lesson_enrollment_id');
    }

    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->access_token_hash, hash('sha256', $token));
    }
}
