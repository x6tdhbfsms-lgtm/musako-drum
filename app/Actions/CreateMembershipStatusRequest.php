<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Models\MembershipStatusRequest;
use App\Models\StudentProfile;
use App\Services\MusakoNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMembershipStatusRequest
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    public function handle(
        StudentProfile $studentProfile,
        MembershipRequestType $type,
        CarbonInterface $effectiveOn,
        ?string $reason,
        ?string $studentNote,
    ): MembershipStatusRequest {
        $membershipStatusRequest = DB::transaction(function () use ($studentProfile, $type, $effectiveOn, $reason, $studentNote): MembershipStatusRequest {
            $lockedStudent = StudentProfile::query()->lockForUpdate()->findOrFail($studentProfile->id);

            $hasOutstandingRequest = $lockedStudent->membershipStatusRequests()
                ->where(function ($query): void {
                    $query->where('status', ApplicationStatus::Pending)
                        ->orWhere(function ($approved): void {
                            $approved->where('status', ApplicationStatus::Approved)->whereNull('applied_at');
                        });
                })
                ->exists();

            if ($hasOutstandingRequest) {
                throw ValidationException::withMessages(['type' => '現在、承認待ちまたは適用待ちの在籍申請があります。']);
            }

            $eligibleStatuses = match ($type) {
                MembershipRequestType::Pause => [EnrollmentStatus::Active->value],
                MembershipRequestType::Withdraw => [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value],
                MembershipRequestType::Resume => [EnrollmentStatus::Paused->value],
            };

            if (! $lockedStudent->enrollments()->whereIn('status', $eligibleStatuses)->exists()) {
                $message = match ($type) {
                    MembershipRequestType::Pause => '現在有効な在籍契約がないため休会申請できません。',
                    MembershipRequestType::Withdraw => '退会対象となる在籍契約がありません。',
                    MembershipRequestType::Resume => '休会中の在籍契約がないため再開申請できません。',
                };

                throw ValidationException::withMessages(['type' => $message]);
            }

            return MembershipStatusRequest::create([
                'student_profile_id' => $lockedStudent->id,
                'type' => $type,
                'effective_on' => $effectiveOn->toDateString(),
                'reason' => $reason,
                'student_note' => $studentNote,
                'status' => ApplicationStatus::Pending,
                'requested_at' => now(),
            ]);
        }, 3);

        $this->notifications->membershipSubmitted($membershipStatusRequest);

        return $membershipStatusRequest;
    }
}
