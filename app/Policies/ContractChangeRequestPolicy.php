<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ContractChangeRequest;
use App\Models\User;

class ContractChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Student, UserRole::Teacher, UserRole::Admin);
    }

    public function view(User $user, ContractChangeRequest $request): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin)
            || $user->studentProfile?->is($request->studentProfile) === true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student && $user->studentProfile !== null;
    }

    public function update(User $user, ContractChangeRequest $request): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin);
    }
}
