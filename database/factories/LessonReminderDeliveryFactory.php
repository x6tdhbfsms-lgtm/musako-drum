<?php

namespace Database\Factories;

use App\Models\LessonReminderDelivery;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonReminderDelivery>
 */
class LessonReminderDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_request_id' => ReservationRequest::factory(),
            'user_id' => User::factory(),
            'lesson_on' => now()->addDay()->toDateString(),
            'queued_at' => now(),
        ];
    }
}
