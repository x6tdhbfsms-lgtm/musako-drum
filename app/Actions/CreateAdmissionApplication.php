<?php

namespace App\Actions;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\LessonType;
use App\Enums\TrialLessonStatus;
use App\Models\AdmissionApplication;
use App\Models\TrialLessonRequest;
use App\Services\MusakoNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateAdmissionApplication
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    /** @param array<string, mixed> $attributes
     * @return array{application: AdmissionApplication, token: string}
     */
    public function handle(TrialLessonRequest $trialLessonRequest, array $attributes): array
    {
        $token = Str::random(64);
        $email = Str::lower(trim((string) $attributes['email']));
        $pendingEmailKey = hash('sha256', $email);

        try {
            $application = DB::transaction(function () use ($trialLessonRequest, $attributes, $token, $email, $pendingEmailKey): AdmissionApplication {
                $trial = TrialLessonRequest::query()->lockForUpdate()->findOrFail($trialLessonRequest->id);
                if ($trial->status !== TrialLessonStatus::Completed) {
                    throw ValidationException::withMessages(['trial' => '体験完了後に入会申込みへ進めます。']);
                }
                if ($trial->admissionApplication()->exists() || AdmissionApplication::query()->where('pending_email_key', $pendingEmailKey)->exists()) {
                    throw ValidationException::withMessages(['email' => 'この体験またはメールアドレスでは入会申込み済みです。']);
                }

                return AdmissionApplication::query()->create([
                    'public_reference' => $this->uniqueReference(),
                    'access_token_hash' => hash('sha256', $token),
                    'pending_email_key' => $pendingEmailKey,
                    'trial_lesson_request_id' => $trial->id,
                    'name' => $attributes['name'],
                    'name_kana' => $attributes['name_kana'],
                    'email' => trim((string) $attributes['email']),
                    'email_normalized' => $email,
                    'phone' => $attributes['phone'],
                    'postal_code' => $attributes['postal_code'] ?? null,
                    'address' => $attributes['address'],
                    'course_id' => $attributes['course_id'],
                    'lesson_type' => $attributes['lesson_type'],
                    'pricing_category' => $attributes['pricing_category'],
                    'monthly_lesson_count' => $attributes['monthly_lesson_count'],
                    'lesson_minutes' => $attributes['lesson_minutes'],
                    'venue_id' => $attributes['venue_id'] ?? null,
                    'teacher_profile_id' => $attributes['teacher_profile_id'] ?? null,
                    'preferred_start_date' => $attributes['preferred_start_date'],
                    'weekday' => $attributes['lesson_type'] === LessonType::Regular->value ? $attributes['weekday'] : null,
                    'starts_at_time' => $attributes['lesson_type'] === LessonType::Regular->value ? $attributes['starts_at_time'] : null,
                    'notes' => $attributes['notes'] ?? null,
                    'status' => AdmissionApplicationStatus::Pending,
                    'privacy_policy_version' => config('musako.privacy.policy_version'),
                    'privacy_consented_at' => now(),
                    'requested_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (AdmissionApplication::query()->where('pending_email_key', $pendingEmailKey)->exists()) {
                throw ValidationException::withMessages(['email' => 'このメールアドレスでは入会申込み済みです。']);
            }

            throw $exception;
        }

        $this->notifications->admissionSubmitted($application);

        return ['application' => $application, 'token' => $token];
    }

    private function uniqueReference(): string
    {
        do {
            $reference = 'AD-'.Str::upper(Str::random(10));
        } while (AdmissionApplication::query()->where('public_reference', $reference)->exists());

        return $reference;
    }
}
