<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\ContractChangeType;
use App\Enums\EnrollmentStatus;
use App\Models\ContractChangeRequest;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewContractChangeRequest
{
    public function handle(ContractChangeRequest $request, User $reviewer, ApplicationStatus $decision, ?string $rejectionReason): ContractChangeRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $decision, $rejectionReason): ContractChangeRequest {
            $lockedRequest = ContractChangeRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($lockedRequest->status !== ApplicationStatus::Pending) {
                throw ValidationException::withMessages(['contract_change_request' => 'この申請はすでに処理されています。']);
            }

            $lockedRequest->update([
                'status' => $decision,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $decision === ApplicationStatus::Rejected ? $rejectionReason : null,
            ]);

            if ($decision === ApplicationStatus::Approved) {
                $this->apply($lockedRequest);
                $lockedRequest->update(['applied_at' => now()]);
            }

            return $lockedRequest->refresh();
        }, 3);
    }

    private function apply(ContractChangeRequest $request): void
    {
        if ($request->type === ContractChangeType::CourseAdd) {
            $this->addCourse($request);

            return;
        }

        $enrollment = LessonEnrollment::query()->lockForUpdate()->findOrFail($request->lesson_enrollment_id);
        $effectiveOn = CarbonImmutable::instance($request->effective_on);
        if ($effectiveOn->lessThanOrEqualTo($enrollment->starts_on) || ($enrollment->ends_on && $effectiveOn->greaterThan($enrollment->ends_on))) {
            throw ValidationException::withMessages(['effective_on' => '契約期間内で、契約開始日より後の日付を指定してください。']);
        }

        $previousEnd = $enrollment->ends_on?->toDateString();
        $enrollment->update(['ends_on' => $effectiveOn->subDay()->toDateString()]);
        $attributes = Arr::only($enrollment->getAttributes(), [
            'student_profile_id', 'course_id', 'lesson_type', 'pricing_category', 'teacher_profile_id', 'venue_id', 'weekday', 'starts_at_time',
            'monthly_lesson_limit', 'lesson_minutes', 'payment_method', 'status',
        ]);
        $attributes = array_merge($attributes, $request->after_values, [
            'starts_on' => $effectiveOn->toDateString(),
            'ends_on' => $previousEnd,
            'supersedes_lesson_enrollment_id' => $enrollment->id,
        ]);
        LessonEnrollment::create($attributes);
    }

    private function addCourse(ContractChangeRequest $request): void
    {
        $base = LessonEnrollment::query()
            ->whereBelongsTo($request->studentProfile)
            ->activeOn(now())
            ->lockForUpdate()
            ->first();
        $course = Course::query()->where('is_active', true)->findOrFail($request->after_values['course_id']);

        LessonEnrollment::create([
            'student_profile_id' => $request->student_profile_id,
            'course_id' => $course->id,
            'lesson_type' => $base?->lesson_type,
            'pricing_category' => $base?->pricing_category,
            'teacher_profile_id' => $base?->teacher_profile_id,
            'venue_id' => $base?->venue_id,
            'weekday' => $base?->weekday,
            'starts_at_time' => $base?->starts_at_time,
            'monthly_lesson_limit' => $course->default_monthly_lessons,
            'lesson_minutes' => $course->default_lesson_minutes,
            'payment_method' => $base?->payment_method,
            'status' => EnrollmentStatus::Active,
            'starts_on' => $request->effective_on,
        ]);
    }
}
