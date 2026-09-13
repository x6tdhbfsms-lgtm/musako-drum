<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonEnrollment>
 */
class LessonEnrollmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'course_id' => Course::factory(),
            'lesson_type' => LessonType::Regular,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_limit' => 4,
            'lesson_minutes' => 60,
            'status' => EnrollmentStatus::Active,
            'starts_on' => now()->startOfMonth(),
        ];
    }
}
