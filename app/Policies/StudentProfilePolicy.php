<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Teacher, UserRole::Admin);
    }

    public function view(User $user, StudentProfile $studentProfile): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role !== UserRole::Teacher) {
            return false;
        }

        $teacherProfileId = TeacherProfile::query()->whereBelongsTo($user)->value('id');

        return $teacherProfileId !== null
            && ($studentProfile->enrollments()->where('teacher_profile_id', $teacherProfileId)->exists()
                || $studentProfile->reservationRequests()->whereHas(
                    'lessonSlot',
                    fn ($slots) => $slots->where('teacher_profile_id', $teacherProfileId),
                )->exists());
    }
}
