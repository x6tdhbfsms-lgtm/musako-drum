<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LessonSlot;
use App\Models\User;

class LessonSlotPolicy
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
    public function view(User $user, LessonSlot $lessonSlot): bool
    {
        return $this->ownsSlotOrIsAdmin($user, $lessonSlot);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Teacher && $user->teacherProfile !== null);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LessonSlot $lessonSlot): bool
    {
        return $this->ownsSlotOrIsAdmin($user, $lessonSlot);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LessonSlot $lessonSlot): bool
    {
        return $this->ownsSlotOrIsAdmin($user, $lessonSlot);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, LessonSlot $lessonSlot): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, LessonSlot $lessonSlot): bool
    {
        return false;
    }

    private function ownsSlotOrIsAdmin(User $user, LessonSlot $lessonSlot): bool
    {
        return $user->role === UserRole::Admin
            || $user->teacherProfile?->is($lessonSlot->teacherProfile) === true;
    }
}
