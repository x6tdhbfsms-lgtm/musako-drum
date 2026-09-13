<?php

namespace App\Actions;

use App\Enums\LessonSlotStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;
use App\Models\TrialLessonRequest;
use App\Services\LessonSlotCapacityService;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateTrialLessonRequest
{
    public function __construct(
        private readonly LessonSlotCapacityService $capacity,
        private readonly MusakoNotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $attributes
     * @return array{request: TrialLessonRequest, token: string}
     */
    public function handle(array $attributes): array
    {
        $token = Str::random(64);
        $email = Str::lower(trim((string) $attributes['email']));

        $trialLessonRequest = DB::transaction(function () use ($attributes, $token, $email): TrialLessonRequest {
            $lessonSlot = LessonSlot::query()->lockForUpdate()->findOrFail($attributes['lesson_slot_id']);

            if ($lessonSlot->status !== LessonSlotStatus::Open
                || ! $lessonSlot->starts_at->isFuture()
                || ! $lessonSlot->booking_audience->acceptsTrial()) {
                throw ValidationException::withMessages(['lesson_slot_id' => 'この枠は体験レッスンを受け付けていません。']);
            }

            if ($this->capacity->availableForTrialApplication($lessonSlot) < 1) {
                throw ValidationException::withMessages(['lesson_slot_id' => 'この枠は満席です。別の日時を選択してください。']);
            }

            $activeSlotKey = hash('sha256', $lessonSlot->id.'|'.$email);
            if (TrialLessonRequest::query()->where('active_slot_key', $activeSlotKey)->exists()) {
                throw ValidationException::withMessages(['email' => 'このメールアドレスでは同じ日時へ申込み済みです。']);
            }

            $request = TrialLessonRequest::query()->create([
                'public_reference' => $this->uniqueReference(),
                'access_token_hash' => hash('sha256', $token),
                'active_slot_key' => $activeSlotKey,
                'lesson_slot_id' => $lessonSlot->id,
                'name' => $attributes['name'],
                'name_kana' => $attributes['name_kana'],
                'email' => trim((string) $attributes['email']),
                'email_normalized' => $email,
                'phone' => $attributes['phone'],
                'age_group' => $attributes['age_group'],
                'drum_experience' => $attributes['drum_experience'],
                'consultation' => $attributes['consultation'] ?? null,
                'status' => TrialLessonStatus::Pending,
                'privacy_policy_version' => config('musako.privacy.policy_version'),
                'privacy_consented_at' => now(),
                'requested_at' => now(),
            ]);

            $request->events()->create([
                'event_type' => 'submitted',
                'to_status' => TrialLessonStatus::Pending->value,
                'occurred_at' => now(),
            ]);

            return $request;
        }, 3);

        $this->notifications->trialSubmitted($trialLessonRequest, $token);

        return ['request' => $trialLessonRequest, 'token' => $token];
    }

    private function uniqueReference(): string
    {
        do {
            $reference = 'TR-'.Str::upper(Str::random(10));
        } while (TrialLessonRequest::query()->where('public_reference', $reference)->exists());

        return $reference;
    }
}
