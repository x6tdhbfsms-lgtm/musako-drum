<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('COURSE-####'),
            'name' => fake()->words(2, true),
            'lesson_type' => 'individual',
            'default_lesson_minutes' => 60,
            'default_monthly_lessons' => 4,
            'default_capacity' => 1,
            'is_active' => true,
        ];
    }
}
