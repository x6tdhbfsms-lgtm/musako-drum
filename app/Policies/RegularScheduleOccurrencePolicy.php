<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\RegularScheduleOccurrence;
use App\Models\User;

class RegularScheduleOccurrencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin);
    }

    public function view(User $user, RegularScheduleOccurrence $occurrence): bool
    {
        return $user->role === UserRole::Admin || $user->teacherProfile?->id === $occurrence->teacher_profile_id;
    }

    public function update(User $user, RegularScheduleOccurrence $occurrence): bool
    {
        return $this->view($user, $occurrence);
    }
}
