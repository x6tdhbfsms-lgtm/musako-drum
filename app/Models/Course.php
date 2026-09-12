<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'lesson_type', 'default_lesson_minutes', 'default_monthly_lessons', 'default_capacity', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(LessonEnrollment::class);
    }
}
