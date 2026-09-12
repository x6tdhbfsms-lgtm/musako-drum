<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TransferRequest;
use App\Models\User;

class TransferRequestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Student, UserRole::Teacher, UserRole::Admin);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TransferRequest $transferRequest): bool
    {
        return $user->role === UserRole::Admin
            || $user->studentProfile?->is($transferRequest->studentProfile) === true
            || $this->belongsToTeacher($user, $transferRequest);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student && $user->studentProfile !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TransferRequest $transferRequest): bool
    {
        return $user->role === UserRole::Admin || $this->belongsToTeacher($user, $transferRequest);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TransferRequest $transferRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TransferRequest $transferRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TransferRequest $transferRequest): bool
    {
        return false;
    }

    private function belongsToTeacher(User $user, TransferRequest $transferRequest): bool
    {
        $teacherProfileId = $user->teacherProfile?->id;

        return $teacherProfileId !== null
            && in_array($teacherProfileId, [
                $transferRequest->originalReservationRequest->lessonSlot->teacher_profile_id,
                $transferRequest->requestedLessonSlot->teacher_profile_id,
            ], true);
    }
}
