<?php

namespace Database\Factories;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Models\AdmissionApplication;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\TrialLessonRequest;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdmissionApplication>
 */
class AdmissionApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'public_reference' => 'AD-'.Str::upper(Str::random(10)),
            'access_token_hash' => hash('sha256', Str::random(64)),
            'pending_email_key' => hash('sha256', strtolower($email)),
            'trial_lesson_request_id' => TrialLessonRequest::factory()->approved(),
            'name' => fake()->name(),
            'name_kana' => 'ムサコ タロウ',
            'email' => $email,
            'email_normalized' => strtolower($email),
            'phone' => '090-1234-5678',
            'postal_code' => '184-0004',
            'address' => '東京都小金井市本町1-1-1',
            'course_id' => Course::factory(),
            'lesson_type' => LessonType::Regular,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_count' => 2,
            'lesson_minutes' => 60,
            'venue_id' => Venue::factory(),
            'teacher_profile_id' => TeacherProfile::factory(),
            'preferred_start_date' => now()->addMonth()->startOfMonth(),
            'weekday' => 3,
            'starts_at_time' => '18:00:00',
            'status' => AdmissionApplicationStatus::Pending,
            'privacy_policy_version' => '2026-09-01',
            'privacy_consented_at' => now(),
            'requested_at' => now(),
        ];
    }
}
