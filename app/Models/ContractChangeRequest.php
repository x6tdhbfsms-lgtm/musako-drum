<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\ContractChangeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractChangeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id', 'lesson_enrollment_id', 'type', 'effective_on', 'before_values',
        'after_values', 'student_note', 'status', 'requested_at', 'reviewed_by_user_id',
        'reviewed_at', 'rejection_reason', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContractChangeType::class,
            'effective_on' => 'date',
            'before_values' => 'array',
            'after_values' => 'array',
            'status' => ApplicationStatus::class,
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
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
