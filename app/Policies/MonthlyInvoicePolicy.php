<?php

namespace App\Policies;

use App\Enums\MonthlyInvoiceStatus;
use App\Enums\UserRole;
use App\Models\MonthlyInvoice;
use App\Models\User;

class MonthlyInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Student, UserRole::Teacher, UserRole::Admin);
    }

    public function view(User $user, MonthlyInvoice $invoice): bool
    {
        if ($user->hasRole(UserRole::Teacher, UserRole::Admin)) {
            return true;
        }

        return $user->role === UserRole::Student
            && in_array($invoice->status, [MonthlyInvoiceStatus::Confirmed, MonthlyInvoiceStatus::Cancelled], true)
            && $invoice->student_profile_id === $user->studentProfile?->id;
    }

    public function manage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
