<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonEnrollment extends Model
{
    use HasFactory;

    protected $fillable = ['student_profile_id', 'course_id', 'monthly_lesson_limit', 'lesson_minutes', 'status', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['status' => EnrollmentStatus::class, 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
