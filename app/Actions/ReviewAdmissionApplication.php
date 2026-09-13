<?php

namespace App\Actions;

use App\Enums\AccountStatus;
use App\Enums\AdmissionApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PricingCategory;
use App\Enums\TrialLessonStatus;
use App\Enums\UserRole;
use App\Models\AdmissionApplication;
use App\Models\LessonEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewAdmissionApplication
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    public function handle(AdmissionApplication $admissionApplication, User $reviewer, AdmissionApplicationStatus $decision, ?string $reason, ?PricingCategory $confirmedPricingCategory = null): AdmissionApplication
    {
        $reviewed = DB::transaction(function () use ($admissionApplication, $reviewer, $decision, $reason, $confirmedPricingCategory): AdmissionApplication {
            $application = AdmissionApplication::query()->lockForUpdate()->findOrFail($admissionApplication->id);
            if ($application->status !== AdmissionApplicationStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'この入会申込みはすでに処理されています。']);
            }

            if ($decision === AdmissionApplicationStatus::Rejected) {
                $application->update([
                    'status' => $decision,
                    'pending_email_key' => null,
                    'processed_by_user_id' => $reviewer->id,
                    'processed_at' => now(),
                    'rejection_reason' => $reason,
                ]);

                return $application->refresh();
            }

            if (User::query()->whereRaw('LOWER(email) = ?', [$application->email_normalized])->exists()) {
                throw ValidationException::withMessages([
                    'email' => '同じメールアドレスのアカウントが存在します。在籍状況と権限を確認してから個別に対応してください。',
                ]);
            }

            if ($confirmedPricingCategory !== null) {
                $application->update(['pricing_category' => $confirmedPricingCategory]);
            }

            $user = User::query()->create([
                'name' => $application->name,
                'email' => $application->email_normalized,
                'password' => Str::random(64),
                'role' => UserRole::Student,
                'account_status' => AccountStatus::Active,
                'email_verified_at' => null,
            ]);
            $student = StudentProfile::query()->create([
                'user_id' => $user->id,
                'student_number' => $this->uniqueStudentNumber(),
                'phone' => $application->phone,
                'postal_code' => $application->postal_code,
                'address' => $application->address,
                'joined_on' => $application->preferred_start_date,
            ]);
            $enrollment = LessonEnrollment::query()->create([
                'student_profile_id' => $student->id,
                'course_id' => $application->course_id,
                'lesson_type' => $application->lesson_type,
                'pricing_category' => $application->pricing_category,
                'teacher_profile_id' => $application->teacher_profile_id,
                'venue_id' => $application->venue_id,
                'weekday' => $application->weekday,
                'starts_at_time' => $application->starts_at_time,
                'monthly_lesson_limit' => $application->monthly_lesson_count,
                'lesson_minutes' => $application->lesson_minutes,
                'status' => EnrollmentStatus::Active,
                'starts_on' => $application->preferred_start_date,
            ]);

            $application->update([
                'status' => AdmissionApplicationStatus::Approved,
                'pending_email_key' => null,
                'processed_by_user_id' => $reviewer->id,
                'processed_at' => now(),
                'converted_user_id' => $user->id,
                'converted_student_profile_id' => $student->id,
                'converted_lesson_enrollment_id' => $enrollment->id,
            ]);
            $application->trialLessonRequest?->update([
                'status' => TrialLessonStatus::Converted,
                'converted_at' => now(),
            ]);
            $application->trialLessonRequest?->events()->create([
                'event_type' => 'converted',
                'from_status' => TrialLessonStatus::Completed->value,
                'to_status' => TrialLessonStatus::Converted->value,
                'actor_user_id' => $reviewer->id,
                'occurred_at' => now(),
            ]);

            return $application->refresh();
        }, 3);

        if ($decision === AdmissionApplicationStatus::Approved) {
            $user = $reviewed->convertedUser;
            try {
                $passwordToken = $user === null ? null : Password::broker()->createToken($user);
            } catch (\Throwable $exception) {
                Log::warning('Initial password setup token could not be created.', [
                    'admission_application_id' => $reviewed->id,
                    'exception' => $exception::class,
                ]);
                $passwordToken = null;
            }
            $this->notifications->admissionReviewed($reviewed, $decision, $passwordToken);
        } else {
            $this->notifications->admissionReviewed($reviewed, $decision);
        }

        return $reviewed;
    }

    private function uniqueStudentNumber(): string
    {
        do {
            $number = 'MUS-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (StudentProfile::query()->where('student_number', $number)->exists());

        return $number;
    }
}
