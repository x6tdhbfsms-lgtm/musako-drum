<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\MembershipRequestType;
use App\Models\MembershipStatusRequest;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipStatusRequest>
 */
class MembershipStatusRequestFactory extends Factory
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
            'type' => MembershipRequestType::Pause,
            'effective_on' => now()->addMonth()->toDateString(),
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
