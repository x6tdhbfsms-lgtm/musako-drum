<?php

return [
    'privacy' => [
        'policy_version' => env('MUSAKO_PRIVACY_POLICY_VERSION', '2026-09-01'),
        'policy_url' => env('MUSAKO_PRIVACY_POLICY_URL', '/privacy'),
    ],

    'public_forms' => [
        'trial_rate_limit_per_minute' => (int) env('TRIAL_FORM_RATE_LIMIT', 5),
        'admission_rate_limit_per_minute' => (int) env('ADMISSION_FORM_RATE_LIMIT', 3),
    ],

    'notifications' => [
        'queue' => env('MUSAKO_NOTIFICATION_QUEUE', 'mail'),
        'lesson_reminder_time' => env('LESSON_REMINDER_TIME', '18:00'),
    ],

    'regular_schedule' => [
        'automatic_generation_enabled' => env('REGULAR_SCHEDULE_AUTOMATIC_GENERATION', true),
        'generation_day' => (int) env('REGULAR_SCHEDULE_GENERATION_DAY', 20),
        'scheduler_time' => env('REGULAR_SCHEDULE_GENERATION_TIME', '00:20'),
    ],
];
