<?php

namespace App\Models;

use App\Enums\TrialLessonStatus;
use Database\Factories\TrialLessonRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TrialLessonRequest extends Model
{
    /** @use HasFactory<TrialLessonRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'public_reference', 'access_token_hash', 'active_slot_key', 'lesson_slot_id', 'name', 'name_kana', 'email',
        'email_normalized', 'phone', 'age_group', 'drum_experience', 'consultation', 'status',
        'privacy_policy_version', 'privacy_consented_at', 'requested_at', 'processed_by_user_id', 'processed_at',
        'rejection_reason', 'approved_at', 'rejected_at', 'cancelled_at', 'completed_at', 'no_show_at', 'converted_at',
    ];

    protected $hidden = ['access_token_hash', 'active_slot_key', 'email_normalized'];

    protected function casts(): array
    {
        return [
            'status' => TrialLessonStatus::class,
            'privacy_consented_at' => 'datetime',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'no_show_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function lessonSlot(): BelongsTo
    {
        return $this->belongsTo(LessonSlot::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TrialLessonRequestEvent::class);
    }

    public function reminderDelivery(): HasOne
    {
        return $this->hasOne(TrialLessonReminderDelivery::class);
    }

    public function admissionApplication(): HasOne
    {
        return $this->hasOne(AdmissionApplication::class);
    }

    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->access_token_hash, hash('sha256', $token));
    }
}
