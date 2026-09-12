<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\User;

class InquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Student, UserRole::Teacher, UserRole::Admin);
    }

    public function view(User $user, Inquiry $inquiry): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin)
            || $user->studentProfile?->is($inquiry->studentProfile) === true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student && $user->studentProfile !== null;
    }

    public function update(User $user, Inquiry $inquiry): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin);
    }
}
