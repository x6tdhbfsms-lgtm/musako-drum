<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\LessonSlot;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use App\Models\TrialLessonRequest;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    /** @return Collection<int, User> */
    public function forLessonSlot(LessonSlot $lessonSlot): Collection
    {
        $lessonSlot->loadMissing('teacherProfile.user');

        return $this->admins()
            ->when(
                $lessonSlot->teacherProfile?->user?->account_status === AccountStatus::Active,
                fn (Collection $users): Collection => $users->push($lessonSlot->teacherProfile->user),
            )
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    public function forStudent(StudentProfile $studentProfile): Collection
    {
        $teacherUsers = User::query()
            ->where('role', UserRole::Teacher)
            ->where('account_status', AccountStatus::Active)
            ->whereHas('teacherProfile.lessonEnrollments', function ($query) use ($studentProfile): void {
                $query->whereBelongsTo($studentProfile)
                    ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused])
                    ->whereDate('starts_on', '<=', today())
                    ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));
            })
            ->get();

        return $this->admins()->merge($teacherUsers)->unique('id')->values();
    }

    /** @return Collection<int, User> */
    public function forTransfer(TransferRequest $transferRequest): Collection
    {
        $transferRequest->loadMissing([
            'originalReservationRequest.lessonSlot.teacherProfile.user',
            'requestedLessonSlot.teacherProfile.user',
        ]);

        return $this->admins()
            ->push($transferRequest->originalReservationRequest->lessonSlot->teacherProfile?->user)
            ->push($transferRequest->requestedLessonSlot->teacherProfile?->user)
            ->filter(fn (?User $user): bool => $user?->account_status === AccountStatus::Active)
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    public function forTrial(TrialLessonRequest $trialLessonRequest): Collection
    {
        return $this->forLessonSlot($trialLessonRequest->lessonSlot);
    }

    /** @return Collection<int, User> */
    public function allStaff(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Teacher, UserRole::Admin])
            ->where('account_status', AccountStatus::Active)
            ->get();
    }

    /** @return Collection<int, User> */
    private function admins(): Collection
    {
        return User::query()
            ->where('role', UserRole::Admin)
            ->where('account_status', AccountStatus::Active)
            ->get();
    }
}
