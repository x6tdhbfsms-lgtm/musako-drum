<?php

return [
    'notifications' => [
        'queue' => env('MUSAKO_NOTIFICATION_QUEUE', 'mail'),
        'lesson_reminder_time' => env('LESSON_REMINDER_TIME', '18:00'),
    ],
];
