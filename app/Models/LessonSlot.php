<?php

namespace App\Models;

use App\Enums\LessonSlotStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonSlot extends Model
{
    use HasFactory;

    protected $fillable = ['teacher_profile_id', 'venue_id', 'course_id', 'starts_at', 'ends_at', 'capacity', 'status', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'status' => LessonSlotStatus::class];
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

    public function reservationRequests(): HasMany
    {
        return $this->hasMany(ReservationRequest::class);
    }
}
