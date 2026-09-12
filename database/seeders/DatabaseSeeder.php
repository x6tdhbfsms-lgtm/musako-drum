<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Venue;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Course::query()->firstOrCreate(['code' => 'INDIVIDUAL-60'], [
            'name' => '個人レッスン60分',
            'lesson_type' => 'individual',
            'default_lesson_minutes' => 60,
            'default_capacity' => 1,
        ]);

        Venue::query()->firstOrCreate(['code' => 'MUSAKO'], [
            'name' => 'MUSAKOドラム教室',
            'timezone' => 'Asia/Tokyo',
        ]);
    }
}
