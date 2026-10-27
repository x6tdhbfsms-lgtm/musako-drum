<?php

namespace App\Actions;

use App\Enums\AccountStatus;
use App\Enums\AdmissionApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Enums\TrialLessonStatus;
use App\Enums\UserRole;
use App\Models\AdmissionApplication;
use App\Models\LessonEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\RegularScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewAdmissionApplication
{
    public function handle(AdmissionApplication $admissionApplication, User $reviewer, AdmissionApplicationStatus $decision, ?string $reason, ?PricingCategory $confirmedPricingCategory = null, ?array $regularWeeks = null): AdmissionApplication
    {
        try {
            $reviewed = DB::transaction(function () use ($admissionApplication, $reviewer, $decision, $reason, $confirmedPricingCategory, $regularWeeks): AdmissionApplication {
                $application = AdmissionApplication::query()->lockForUpdate()->findOrFail($admissionApplication->id);
                if ($application->status !== AdmissionApplicationStatus::Pending) {
                    throw ValidationException::withMessages(['status' => 'この入会申込みはすでに処理されています。']);
                }

                if ($decision === AdmissionApplicationStatus::Rejected) {
                    $application->update([
                        'status' => $decision,
                        'pending_email_key' => null,
                        'pending_login_name' => null,
                        'initial_password_hash' => null,
                        'processed_by_user_id' => $reviewer->id,
                        'processed_at' => now(),
                        'rejection_reason' => $reason,
                    ]);

                    return $application->refresh();
                }

                if ($application->login_name !== null && User::where('login_name', $application->login_name)->exists()) {
                    throw ValidationException::withMessages(['login_name' => 'このログイン名は使用済みです。']);
                }

                if ($application->login_name === null && $application->email_normalized !== null && User::whereRaw('LOWER(email) = ?', [$application->email_normalized])->exists()) {
                    throw ValidationException::withMessages(['email' => '同じメールアドレスのアカウントが存在します。既存アカウントを確認してください。']);
                }

                if ($confirmedPricingCategory !== null) {
                    $application->update(['pricing_category' => $confirmedPricingCategory]);
                }

                $category = $confirmedPricingCategory ?? $application->pricing_category;
                if ($category === PricingCategory::Kids && (! str_contains($application->course->code, 'ELECTRONIC-') || ! in_array((int) $application->lesson_minutes, [30, 45], true) || (int) $application->course->default_lesson_minutes !== (int) $application->lesson_minutes || $application->venue?->termsRoomType() !== 'music_room')) {
                    throw ValidationException::withMessages(['pricing_category' => 'キッズ料金は電子ドラム30分・45分のみ選択できます。']);
                }

                $user = User::query()->create([
                    'name' => $application->name,
                    'email' => $application->email_normalized,
                    'login_name' => $application->login_name,
                    'password' => $application->initial_password_hash ?? Str::random(64),
                    'role' => UserRole::Student,
                    'account_status' => AccountStatus::Active,
                    'email_verified_at' => null,
                ]);
                if ($user->login_name === null) {
                    $loginName = 'student-'.$user->id;
                    while (User::where('login_name', $loginName)->exists() || AdmissionApplication::where('pending_login_name', $loginName)->exists()) {
                        $loginName = 'student-'.$user->id.'-'.Str::lower(Str::random(6));
                    }
                    $user->update(['login_name' => $loginName]);
                }
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
                    'regular_week_numbers' => $application->lesson_type === LessonType::Regular ? $regularWeeks : null,
                    'monthly_lesson_limit' => $application->monthly_lesson_count,
                    'lesson_minutes' => $application->lesson_minutes,
                    'status' => EnrollmentStatus::Active,
                    'starts_on' => $application->preferred_start_date,
                ]);

                if ($enrollment->lesson_type === LessonType::Regular
                    && $enrollment->teacher_profile_id !== null && $enrollment->weekday !== null
                    && $enrollment->starts_at_time !== null && $enrollment->lesson_minutes > 0) {
                    $firstMonth = CarbonImmutable::instance($enrollment->starts_on)->startOfMonth()->max(CarbonImmutable::today()->startOfMonth());
                    $generator = app(RegularScheduleGenerator::class);
                    $today = CarbonImmutable::today(config('app.timezone'));
                    $lastMonth = $today->startOfMonth()->addMonths(2)->max($firstMonth);
                    $occurrenceIds = [];
                    for ($month = $firstMonth; $month->lessThanOrEqualTo($lastMonth); $month = $month->addMonth()) {
                        $batch = $generator->generateEnrollment($enrollment, $month, $reviewer, notBefore: $today);
                        $occurrenceIds = array_merge($occurrenceIds, $batch->occurrences->modelKeys());
                    }
                    app(ConfirmRegularScheduleOccurrences::class)->handle($occurrenceIds, $reviewer);
                }

                $application->update([
                    'status' => AdmissionApplicationStatus::Approved,
                    'pending_email_key' => null,
                    'pending_login_name' => null,
                    'initial_password_hash' => null,
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
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'login_name')) {
                throw ValidationException::withMessages(['login_name' => 'このログイン名は使用済みです。別のログイン名を設定してください。']);
            }
            throw $exception;
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
