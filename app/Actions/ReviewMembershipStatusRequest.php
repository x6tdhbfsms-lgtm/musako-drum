<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\MembershipStatusRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewMembershipStatusRequest
{
    public function __construct(private ApplyApprovedMembershipStatusRequest $applyApprovedRequest) {}

    public function handle(
        MembershipStatusRequest $membershipStatusRequest,
        User $reviewer,
        ApplicationStatus $decision,
        ?string $staffNote,
    ): MembershipStatusRequest {
        return DB::transaction(function () use ($membershipStatusRequest, $reviewer, $decision, $staffNote): MembershipStatusRequest {
            $lockedRequest = MembershipStatusRequest::query()->lockForUpdate()->findOrFail($membershipStatusRequest->id);

            if ($lockedRequest->status !== ApplicationStatus::Pending) {
                throw ValidationException::withMessages(['membership_status_request' => 'この申請はすでに処理されています。']);
            }

            $lockedRequest->update([
                'status' => $decision,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'staff_note' => $staffNote,
            ]);

            if ($decision === ApplicationStatus::Approved) {
                return $this->applyApprovedRequest->handle($lockedRequest);
            }

            return $lockedRequest->refresh();
        }, 3);
    }
}
