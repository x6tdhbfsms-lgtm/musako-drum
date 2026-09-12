<?php

namespace App\Actions;

use App\Enums\EnrollmentStatus;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReservationRequest
{
    public function handle(StudentProfile $studentProfile, LessonSlot $lessonSlot, ?string $studentNote): ReservationRequest
    {
        return DB::transaction(function () use ($studentProfile, $lessonSlot, $studentNote): ReservationRequest {
            $lockedSlot = LessonSlot::query()->lockForUpdate()->findOrFail($lessonSlot->id);

            if ($lockedSlot->status !== LessonSlotStatus::Open || ! $lockedSlot->starts_at->isFuture()) {
                throw ValidationException::withMessages(['lesson_slot' => 'この枠は現在予約できません。']);
            }

            if ($lockedSlot->reservationRequests()->where('status', ReservationStatus::Approved)->count() >= $lockedSlot->capacity) {
                throw ValidationException::withMessages(['lesson_slot' => 'この枠は満席です。']);
            }

            if ($lockedSlot->reservationRequests()->whereBelongsTo($studentProfile)->exists()) {
                throw ValidationException::withMessages(['lesson_slot' => 'この枠にはすでに申し込み済みです。']);
            }

            $enrollment = $studentProfile->enrollments()
                ->where('status', EnrollmentStatus::Active)
                ->when($lockedSlot->course_id, fn ($query) => $query->where('course_id', $lockedSlot->course_id))
                ->whereDate('starts_on', '<=', $lockedSlot->starts_at)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $lockedSlot->starts_at))
                ->first();

            return ReservationRequest::create([
                'student_profile_id' => $studentProfile->id,
                'lesson_slot_id' => $lockedSlot->id,
                'lesson_enrollment_id' => $enrollment?->id,
                'status' => ReservationStatus::Pending,
                'requested_at' => now(),
                'student_note' => $studentNote,
            ]);
        }, 3);
    }
}
