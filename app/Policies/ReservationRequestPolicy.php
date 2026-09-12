<?php

namespace App\Policies;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\ReservationRequest;
use App\Models\User;

class ReservationRequestPolicy
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
    public function view(User $user, ReservationRequest $reservationRequest): bool
    {
        return $this->belongsToStudent($user, $reservationRequest)
            || $this->belongsToTeacher($user, $reservationRequest)
            || $user->role === UserRole::Admin;
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
    public function update(User $user, ReservationRequest $reservationRequest): bool
    {
        return $user->role === UserRole::Admin || $this->belongsToTeacher($user, $reservationRequest);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ReservationRequest $reservationRequest): bool
    {
        return $this->belongsToStudent($user, $reservationRequest)
            && in_array($reservationRequest->status, [ReservationStatus::Pending, ReservationStatus::Approved], true)
            && $reservationRequest->lessonSlot->starts_at->isFuture();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ReservationRequest $reservationRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ReservationRequest $reservationRequest): bool
    {
        return false;
    }

    private function belongsToStudent(User $user, ReservationRequest $reservationRequest): bool
    {
        return $user->studentProfile?->is($reservationRequest->studentProfile) === true;
    }

    private function belongsToTeacher(User $user, ReservationRequest $reservationRequest): bool
    {
        return $user->teacherProfile?->is($reservationRequest->lessonSlot->teacherProfile) === true;
    }
}
