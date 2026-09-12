<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MembershipStatusRequest;
use App\Models\User;

class MembershipStatusRequestPolicy
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
    public function view(User $user, MembershipStatusRequest $membershipStatusRequest): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin)
            || $user->studentProfile?->is($membershipStatusRequest->studentProfile) === true;
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
    public function update(User $user, MembershipStatusRequest $membershipStatusRequest): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MembershipStatusRequest $membershipStatusRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MembershipStatusRequest $membershipStatusRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MembershipStatusRequest $membershipStatusRequest): bool
    {
        return false;
    }
}
