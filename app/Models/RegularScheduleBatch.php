<?php

namespace App\Models;

use App\Enums\RegularScheduleBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegularScheduleBatch extends Model
{
    protected $fillable = [
        'lesson_enrollment_id', 'entitlement_month', 'expected_count', 'generated_count',
        'status', 'warning', 'generated_by_user_id', 'generated_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'entitlement_month' => 'date',
            'status' => RegularScheduleBatchStatus::class,
            'generated_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function lessonEnrollment(): BelongsTo
    {
        return $this->belongsTo(LessonEnrollment::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RegularScheduleOccurrence::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
