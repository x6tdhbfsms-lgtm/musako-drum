<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PersonalInformationChangeRequest;
use App\Models\User;

class PersonalInformationChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Student, UserRole::Teacher, UserRole::Admin);
    }

    public function view(User $user, PersonalInformationChangeRequest $request): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin)
            || $user->studentProfile?->is($request->studentProfile) === true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student && $user->studentProfile !== null;
    }

    public function update(User $user, PersonalInformationChangeRequest $request): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin);
    }
}
