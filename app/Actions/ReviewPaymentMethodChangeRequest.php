<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\LessonEnrollment;
use App\Models\PaymentMethodChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewPaymentMethodChangeRequest
{
    public function handle(PaymentMethodChangeRequest $request, User $reviewer, ApplicationStatus $decision, ?string $rejectionReason): PaymentMethodChangeRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $decision, $rejectionReason): PaymentMethodChangeRequest {
            $lockedRequest = PaymentMethodChangeRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($lockedRequest->status !== ApplicationStatus::Pending) {
                throw ValidationException::withMessages(['payment_method_change_request' => 'この申請はすでに処理されています。']);
            }

            if ($decision === ApplicationStatus::Approved && $lockedRequest->lesson_enrollment_id !== null) {
                LessonEnrollment::query()->lockForUpdate()->findOrFail($lockedRequest->lesson_enrollment_id)
                    ->update(['payment_method' => $lockedRequest->requested_method]);
                $lockedRequest->applied_at = now();
            }

            $lockedRequest->fill([
                'status' => $decision,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $decision === ApplicationStatus::Rejected ? $rejectionReason : null,
            ])->save();

            return $lockedRequest->refresh();
        }, 3);
    }
}
