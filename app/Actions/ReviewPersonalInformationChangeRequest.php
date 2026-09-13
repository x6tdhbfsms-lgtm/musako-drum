<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\PersonalInformationChangeRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewPersonalInformationChangeRequest
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    public function handle(PersonalInformationChangeRequest $request, User $reviewer, ApplicationStatus $decision, ?string $rejectionReason): PersonalInformationChangeRequest
    {
        $reviewedRequest = DB::transaction(function () use ($request, $reviewer, $decision, $rejectionReason): PersonalInformationChangeRequest {
            $lockedRequest = PersonalInformationChangeRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($lockedRequest->status !== ApplicationStatus::Pending) {
                throw ValidationException::withMessages(['personal_information_change_request' => 'この申請はすでに処理されています。']);
            }

            if ($decision === ApplicationStatus::Approved) {
                $profile = StudentProfile::query()->lockForUpdate()->findOrFail($lockedRequest->student_profile_id);
                $user = User::query()->lockForUpdate()->findOrFail($profile->user_id);
                $email = $lockedRequest->after_values['email'] ?? $user->email;
                if (User::query()->where('email', $email)->whereKeyNot($user->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'このメールアドレスは別のアカウントで使用されています。']);
                }
                $user->update(array_intersect_key($lockedRequest->after_values, array_flip(['name', 'email'])));
                $profile->update(array_intersect_key($lockedRequest->after_values, array_flip(['phone', 'address'])));
            }

            $lockedRequest->update([
                'status' => $decision,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $decision === ApplicationStatus::Rejected ? $rejectionReason : null,
            ]);

            return $lockedRequest->refresh();
        }, 3);

        $this->notifications->personalInformationChangeReviewed($reviewedRequest, $decision);

        return $reviewedRequest;
    }
}
