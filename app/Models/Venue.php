<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'lesson_room_type', 'address', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function termsRoomType(): ?string
    {
        if ($this->lesson_room_type !== null) {
            return $this->lesson_room_type;
        }

        return match ($this->name) {
            'studio34' => 'studio34',
            '電子ドラムコース', '電子ドラムコース（別棟音楽室）', '別棟音楽室' => 'music_room',
            default => null,
        };
    }

    public function lessonSlots(): HasMany
    {
        return $this->hasMany(LessonSlot::class);
    }
}
