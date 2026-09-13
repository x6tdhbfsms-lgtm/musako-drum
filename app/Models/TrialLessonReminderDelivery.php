<?php

namespace App\Models;

use Database\Factories\TrialLessonReminderDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrialLessonReminderDelivery extends Model
{
    /** @use HasFactory<TrialLessonReminderDeliveryFactory> */
    use HasFactory;

    protected $fillable = ['trial_lesson_request_id', 'lesson_on', 'queued_at', 'sent_at', 'failed_at', 'attempts', 'last_error'];

    protected function casts(): array
    {
        return ['lesson_on' => 'date', 'queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function trialLessonRequest(): BelongsTo
    {
        return $this->belongsTo(TrialLessonRequest::class);
    }
}
