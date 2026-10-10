<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationRequest>
 */
class ReservationRequestFactory extends Factory
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
            'lesson_slot_id' => LessonSlot::factory(),
            'lesson_enrollment_id' => fn (array $attributes) => LessonEnrollment::factory()->create([
                'student_profile_id' => $attributes['student_profile_id'],
                'course_id' => LessonSlot::findOrFail($attributes['lesson_slot_id'])->course_id,
            ])->id,
            'status' => ReservationStatus::Pending,
            'requested_at' => now(),
        ];
    }
}
