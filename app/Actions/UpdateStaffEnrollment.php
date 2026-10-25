<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\ContractChangeType;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Models\ContractChangeRequest;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\RegularScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateStaffEnrollment
{
    public function handle(StudentProfile $student, LessonEnrollment $enrollment, User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $student);
        abort_unless($enrollment->student_profile_id === $student->id, 404);
        DB::transaction(function () use ($student, $enrollment, $actor, $data): void {
            StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $current = LessonEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            if ($current->status !== EnrollmentStatus::Active || $current->replacements()->exists()) {
                throw ValidationException::withMessages(['effective_on' => '変更済み、休会中、終了済みの契約は編集できません。最新の契約を確認してください。']);
            }
            if ($student->contractChangeRequests()->where('lesson_enrollment_id', $current->id)->where('status', ApplicationStatus::Pending)->exists()
                || $student->membershipStatusRequests()->where('status', ApplicationStatus::Pending)->exists()
                || $student->paymentMethodChangeRequests()->where('lesson_enrollment_id', $current->id)->where('status', ApplicationStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['effective_on' => 'この契約に関する承認待ちの申請があります。先に申請を確認してください。']);
            }
            $course = Course::where('is_active', true)->findOrFail($data['course_id']);
            $after = Arr::only($data, ['course_id', 'lesson_type', 'pricing_category', 'monthly_lesson_limit', 'venue_id', 'teacher_profile_id', 'payment_method', 'weekday', 'starts_at_time']);
            $after['lesson_minutes'] = $course->default_lesson_minutes;
            $after['payment_method'] ??= null;
            if ($after['lesson_type'] === LessonType::Flex->value) {
                $after['weekday'] = null;
                $after['starts_at_time'] = null;
                $after['regular_week_numbers'] = null;
            } elseif ((int) $after['monthly_lesson_limit'] !== (int) $current->monthly_lesson_limit) {
                $after['regular_week_numbers'] = null;
            }
            $before = Arr::only($current->getAttributes(), array_keys($after));
            $change = ContractChangeRequest::create([
                'student_profile_id' => $student->id, 'lesson_enrollment_id' => $current->id,
                'type' => ContractChangeType::AdministrativeEdit, 'effective_on' => $data['effective_on'],
                'before_values' => $before, 'after_values' => $after,
                'student_note' => '管理者による直接編集：'.$data['reason'],
                'status' => ApplicationStatus::Pending, 'requested_at' => now(),
            ]);
            app(ReviewContractChangeRequest::class)->handle($change, $actor, ApplicationStatus::Approved, null);
            if ($after['lesson_type'] === LessonType::Regular->value) {
                $today = CarbonImmutable::today(config('app.timezone'));
                $firstMonth = CarbonImmutable::parse($data['effective_on'])->startOfMonth()->max($today->startOfMonth());
                $lastMonth = $today->startOfMonth()->addMonths(2)->max($firstMonth);
                $generator = app(RegularScheduleGenerator::class);
                for ($month = $firstMonth; $month->lessThanOrEqualTo($lastMonth); $month = $month->addMonth()) {
                    $batches = $generator->generateMonth($month, $actor, notBefore: $today, studentId: $student->id);
                    $ids = $batches->flatMap(fn ($batch) => $batch->occurrences->modelKeys())->all();
                    app(ConfirmRegularScheduleOccurrences::class)->handle($ids, $actor);
                }
            }
        }, 3);
    }
}
