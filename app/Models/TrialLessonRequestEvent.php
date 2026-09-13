<?php

namespace App\Models;

use Database\Factories\TrialLessonRequestEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrialLessonRequestEvent extends Model
{
    /** @use HasFactory<TrialLessonRequestEventFactory> */
    use HasFactory;

    protected $fillable = ['trial_lesson_request_id', 'event_type', 'from_status', 'to_status', 'actor_user_id', 'note', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function trialLessonRequest(): BelongsTo
    {
        return $this->belongsTo(TrialLessonRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
