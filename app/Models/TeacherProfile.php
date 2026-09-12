<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherProfile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'display_name', 'bio', 'is_accepting_bookings'];

    protected function casts(): array
    {
        return ['is_accepting_bookings' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lessonSlots(): HasMany
    {
        return $this->hasMany(LessonSlot::class);
    }
}
