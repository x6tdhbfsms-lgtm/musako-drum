<?php

namespace Database\Factories;

use App\Enums\LessonSlotAudience;
use App\Enums\LessonSlotStatus;
use App\Models\Course;
use App\Models\LessonSlot;
use App\Models\TeacherProfile;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonSlot>
 */
class LessonSlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'venue_id' => Venue::factory(),
            'course_id' => Course::factory(),
            'starts_at' => now()->addWeek()->startOfHour(),
            'ends_at' => now()->addWeek()->startOfHour()->addHour(),
            'capacity' => 1,
            'status' => LessonSlotStatus::Open,
            'booking_audience' => LessonSlotAudience::Regular,
        ];
    }

    public function forTrials(): static
    {
        return $this->state(fn (): array => ['booking_audience' => LessonSlotAudience::Trial]);
    }

    public function forAllBookings(): static
    {
        return $this->state(fn (): array => ['booking_audience' => LessonSlotAudience::Both]);
    }
}
