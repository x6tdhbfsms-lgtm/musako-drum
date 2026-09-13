<?php

namespace App\Actions;

use App\Enums\EnrollmentStatus;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Services\MusakoNotificationService;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateReservationRequest
{
    public function __construct(
        private readonly MonthlyLessonUsageCalculator $monthlyLessonUsage,
        private readonly MusakoNotificationService $notifications,
    ) {}

    public function handle(StudentProfile $studentProfile, LessonSlot $lessonSlot, ?string $studentNote): ReservationRequest
    {
        $reservationRequest = DB::transaction(function () use ($studentProfile, $lessonSlot, $studentNote): ReservationRequest {
            $lockedStudent = StudentProfile::query()->lockForUpdate()->findOrFail($studentProfile->id);
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

            $enrollment = $lockedStudent->enrollments()
                ->where('status', EnrollmentStatus::Active)
                ->when($lockedSlot->course_id, fn ($query) => $query->where('course_id', $lockedSlot->course_id))
                ->whereDate('starts_on', '<=', $lockedSlot->starts_at)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $lockedSlot->starts_at))
                ->first();

            $entitlementMonth = CarbonImmutable::instance($lockedSlot->starts_at)->startOfMonth();
            if ($enrollment?->monthly_lesson_limit !== null) {
                $summary = $this->monthlyLessonUsage->calculate($lockedStudent, $entitlementMonth);
                if ($summary->remaining < 1) {
                    throw ValidationException::withMessages([
                        'lesson_slot' => '今月の予約可能回数を使い切っています。',
                    ]);
                }
            }

            return ReservationRequest::create([
                'student_profile_id' => $lockedStudent->id,
                'lesson_slot_id' => $lockedSlot->id,
                'lesson_enrollment_id' => $enrollment?->id,
                'lesson_entitlement_month' => $entitlementMonth->toDateString(),
                'status' => ReservationStatus::Pending,
                'requested_at' => now(),
                'student_note' => $studentNote,
            ]);
        }, 3);

        $this->notifications->reservationSubmitted($reservationRequest);

        return $reservationRequest;
    }
}
