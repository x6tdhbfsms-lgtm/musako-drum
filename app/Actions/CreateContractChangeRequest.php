<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\ContractChangeType;
use App\Models\ContractChangeRequest;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\StudentProfile;
use App\Models\Venue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateContractChangeRequest
{
    public function handle(StudentProfile $studentProfile, array $data): ContractChangeRequest
    {
        return DB::transaction(function () use ($studentProfile, $data): ContractChangeRequest {
            StudentProfile::query()->lockForUpdate()->findOrFail($studentProfile->id);
            $type = ContractChangeType::from($data['type']);
            $enrollment = isset($data['lesson_enrollment_id'])
                ? LessonEnrollment::query()->lockForUpdate()->findOrFail($data['lesson_enrollment_id'])
                : null;

            if ($enrollment !== null && $enrollment->student_profile_id !== $studentProfile->id) {
                throw ValidationException::withMessages(['lesson_enrollment_id' => '選択した契約を確認できません。']);
            }

            if ($type !== ContractChangeType::CourseAdd && $enrollment === null) {
                throw ValidationException::withMessages(['lesson_enrollment_id' => '変更する契約を選択してください。']);
            }

            $hasDuplicate = ContractChangeRequest::query()
                ->whereBelongsTo($studentProfile)
                ->where('type', $type)
                ->where('status', ApplicationStatus::Pending)
                ->when($enrollment !== null, fn ($query) => $query->whereBelongsTo($enrollment, 'lessonEnrollment'))
                ->exists();
            if ($hasDuplicate) {
                throw ValidationException::withMessages(['type' => '同じ内容の承認待ち申請があります。']);
            }

            $beforeValues = $enrollment === null ? null : $this->snapshot($enrollment);
            $afterValues = match ($type) {
                ContractChangeType::Schedule => Arr::only($data, ['weekday', 'starts_at_time']),
                ContractChangeType::MonthlyLessons => Arr::only($data, ['monthly_lesson_limit']),
                ContractChangeType::LessonMinutes => Arr::only($data, ['lesson_minutes']),
                ContractChangeType::CourseChange, ContractChangeType::CourseAdd => [
                    'course_id' => Course::query()->where('is_active', true)->findOrFail($data['course_id'])->id,
                ],
                ContractChangeType::VenueChange => [
                    'venue_id' => Venue::query()->where('is_active', true)->findOrFail($data['venue_id'])->id,
                ],
            };

            return ContractChangeRequest::create([
                'student_profile_id' => $studentProfile->id,
                'lesson_enrollment_id' => $enrollment?->id,
                'type' => $type,
                'effective_on' => $data['effective_on'],
                'before_values' => $beforeValues,
                'after_values' => $afterValues,
                'student_note' => $data['student_note'] ?? null,
                'status' => ApplicationStatus::Pending,
                'requested_at' => now(),
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(LessonEnrollment $enrollment): array
    {
        return Arr::only($enrollment->getAttributes(), [
            'course_id', 'teacher_profile_id', 'venue_id', 'weekday', 'starts_at_time',
            'monthly_lesson_limit', 'lesson_minutes', 'payment_method', 'status', 'starts_on', 'ends_on',
        ]);
    }
}
