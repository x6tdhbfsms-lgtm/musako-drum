<?php

namespace Database\Factories;

use App\Models\TrialLessonReminderDelivery;
use App\Models\TrialLessonRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrialLessonReminderDelivery>
 */
class TrialLessonReminderDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trial_lesson_request_id' => TrialLessonRequest::factory()->approved(),
            'lesson_on' => now()->addDay()->toDateString(),
            'queued_at' => now(),
        ];
    }
}
