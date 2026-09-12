<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferRequest>
 */
class TransferRequestFactory extends Factory
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
            'original_reservation_request_id' => ReservationRequest::factory(),
            'requested_lesson_slot_id' => LessonSlot::factory(),
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
