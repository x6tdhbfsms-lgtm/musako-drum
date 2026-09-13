<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TrialLessonRequest;
use App\Models\User;

class TrialLessonRequestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TrialLessonRequest $trialLessonRequest): bool
    {
        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Teacher
                && $user->teacherProfile?->id === $trialLessonRequest->lessonSlot->teacher_profile_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TrialLessonRequest $trialLessonRequest): bool
    {
        return $this->view($user, $trialLessonRequest);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TrialLessonRequest $trialLessonRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TrialLessonRequest $trialLessonRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TrialLessonRequest $trialLessonRequest): bool
    {
        return false;
    }
}
