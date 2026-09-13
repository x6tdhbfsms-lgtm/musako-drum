<?php

namespace Database\Factories;

use App\Enums\TrialLessonStatus;
use App\Models\TrialLessonRequest;
use App\Models\TrialLessonRequestEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrialLessonRequestEvent>
 */
class TrialLessonRequestEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trial_lesson_request_id' => TrialLessonRequest::factory(),
            'event_type' => 'submitted',
            'to_status' => TrialLessonStatus::Pending->value,
            'occurred_at' => now(),
        ];
    }
}
