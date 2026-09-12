<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Models\MembershipStatusRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyApprovedMembershipStatusRequest
{
    public function handle(MembershipStatusRequest $membershipStatusRequest): MembershipStatusRequest
    {
        return DB::transaction(function () use ($membershipStatusRequest): MembershipStatusRequest {
            $lockedRequest = MembershipStatusRequest::query()->lockForUpdate()->findOrFail($membershipStatusRequest->id);

            if ($lockedRequest->status !== ApplicationStatus::Approved || $lockedRequest->applied_at !== null) {
                return $lockedRequest;
            }

            if ($lockedRequest->effective_on->greaterThan(today())) {
                return $lockedRequest;
            }

            $eligibleStatuses = match ($lockedRequest->type) {
                MembershipRequestType::Pause => [EnrollmentStatus::Active->value],
                MembershipRequestType::Withdraw => [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value],
                MembershipRequestType::Resume => [EnrollmentStatus::Paused->value],
            };
            $enrollments = $lockedRequest->studentProfile->enrollments()
                ->whereIn('status', $eligibleStatuses)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($enrollments->isEmpty()) {
                throw ValidationException::withMessages(['membership_status_request' => '申請を反映できる在籍契約がありません。']);
            }

            $previousStatuses = $enrollments->mapWithKeys(fn ($enrollment) => [
                (string) $enrollment->id => $enrollment->status->value,
            ])->all();
            $newStatus = match ($lockedRequest->type) {
                MembershipRequestType::Pause => EnrollmentStatus::Paused,
                MembershipRequestType::Withdraw => EnrollmentStatus::Ended,
                MembershipRequestType::Resume => EnrollmentStatus::Active,
            };

            foreach ($enrollments as $enrollment) {
                $enrollment->update([
                    'status' => $newStatus,
                    'ends_on' => $lockedRequest->type === MembershipRequestType::Withdraw
                        ? $lockedRequest->effective_on
                        : ($lockedRequest->type === MembershipRequestType::Resume ? null : $enrollment->ends_on),
                ]);
            }

            $lockedRequest->update([
                'previous_enrollment_statuses' => $previousStatuses,
                'applied_at' => now(),
            ]);

            return $lockedRequest->refresh();
        }, 3);
    }
}
