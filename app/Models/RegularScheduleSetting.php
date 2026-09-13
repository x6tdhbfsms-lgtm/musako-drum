<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegularScheduleSetting extends Model
{
    protected $fillable = ['automatic_generation_enabled', 'generation_day'];

    protected function casts(): array
    {
        return ['automatic_generation_enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'automatic_generation_enabled' => (bool) config('musako.regular_schedule.automatic_generation_enabled', true),
            'generation_day' => (int) config('musako.regular_schedule.generation_day', 20),
        ]);
    }
}
