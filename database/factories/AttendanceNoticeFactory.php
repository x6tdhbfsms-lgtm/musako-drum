<?php

namespace Database\Factories;

use App\Enums\AttendanceNoticeType;
use App\Models\AttendanceNotice;
use App\Models\ReservationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceNotice>
 */
class AttendanceNoticeFactory extends Factory
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
            'type' => AttendanceNoticeType::Absence,
            'submitted_at' => now(),
        ];
    }
}
