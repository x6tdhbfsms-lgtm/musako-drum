<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\StudentProfile;
use Illuminate\Validation\ValidationException;

class StudentLessonEligibility
{
    public function reason(StudentProfile $student, LessonSlot $slot, ?LessonEnrollment $enrollment = null, ?int $exceptReservationId = null): ?string
    {
        if ($student->user->account_status !== AccountStatus::Active) {
            return '現在利用できないアカウントのため予約を確定できません。';
        }
        $latest = $student->membershipStatusRequests()->where('status', ApplicationStatus::Approved)
            ->whereDate('effective_on', '<=', $slot->starts_at)->orderByDesc('effective_on')->orderByDesc('id')->first();
        if ($latest !== null && $latest->type !== MembershipRequestType::Resume) {
            return '休会・退会期間のため予約を確定できません。';
        }
        if ($enrollment !== null && ($enrollment->student_profile_id !== $student->id
            || ! in_array($enrollment->status, [EnrollmentStatus::Active, EnrollmentStatus::Ended], true)
            || ($enrollment->status === EnrollmentStatus::Ended && $enrollment->ends_on === null)
            || $enrollment->starts_on->toDateString() > $slot->starts_at->toDateString()
            || ($enrollment->ends_on !== null && $enrollment->ends_on->toDateString() < $slot->starts_at->toDateString()))) {
            return '対象日時に有効な在籍契約がないため予約を確定できません。';
        }
        if ($student->reservationRequests()->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Approved])
            ->when($exceptReservationId !== null, fn ($query) => $query->whereKeyNot($exceptReservationId))
            ->whereHas('lessonSlot', fn ($query) => $query->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at))->exists()) {
            return '生徒本人に同時間帯の予約があります。';
        }

        return null;
    }

    public function assertEligible(StudentProfile $student, LessonSlot $slot, string $errorKey, ?LessonEnrollment $enrollment = null, ?int $exceptReservationId = null): void
    {
        $reason = $this->reason($student, $slot, $enrollment, $exceptReservationId);
        if ($reason !== null) {
            throw ValidationException::withMessages([$errorKey => $reason]);
        }
    }
}
