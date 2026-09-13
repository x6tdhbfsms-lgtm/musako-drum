<?php

namespace Database\Factories;

use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;
use App\Models\TrialLessonRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrialLessonRequest>
 */
class TrialLessonRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);
        $email = fake()->unique()->safeEmail();

        return [
            'public_reference' => 'TR-'.Str::upper(Str::random(10)),
            'access_token_hash' => hash('sha256', $token),
            'active_slot_key' => hash('sha256', $email.'|'.fake()->uuid()),
            'lesson_slot_id' => LessonSlot::factory()->forTrials(),
            'name' => fake()->name(),
            'name_kana' => 'ムサコ タロウ',
            'email' => $email,
            'email_normalized' => strtolower($email),
            'phone' => '090-1234-5678',
            'age_group' => 'adult',
            'drum_experience' => 'beginner',
            'consultation' => '初心者です。',
            'status' => TrialLessonStatus::Pending,
            'privacy_policy_version' => '2026-09-01',
            'privacy_consented_at' => now(),
            'requested_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => TrialLessonStatus::Approved, 'approved_at' => now()]);
    }
}
