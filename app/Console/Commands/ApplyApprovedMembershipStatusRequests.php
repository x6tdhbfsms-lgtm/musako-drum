<?php

namespace App\Console\Commands;

use App\Actions\ApplyApprovedMembershipStatusRequest;
use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Models\MembershipStatusRequest;
use App\Models\StudentProfile;
use App\Services\PausedScheduleRelease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('membership-requests:apply-approved')]
#[Description('Apply approved membership status requests whose effective date has arrived')]
class ApplyApprovedMembershipStatusRequests extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ApplyApprovedMembershipStatusRequest $applyApprovedRequest, PausedScheduleRelease $scheduleRelease): int
    {
        StudentProfile::query()->whereHas('enrollments', fn ($query) => $query->where('status', EnrollmentStatus::Paused))
            ->chunkById(100, fn ($students) => $students->each(fn ($student) => $scheduleRelease->release($student)));
        $processed = 0;

        MembershipStatusRequest::query()
            ->where('status', ApplicationStatus::Approved)
            ->whereNull('applied_at')
            ->whereDate('effective_on', '<=', today())
            ->orderBy('id')
            ->chunkById(100, function ($requests) use ($applyApprovedRequest, &$processed): void {
                foreach ($requests as $membershipStatusRequest) {
                    $applyApprovedRequest->handle($membershipStatusRequest);
                    $processed++;
                }
            });

        $this->info("Applied {$processed} membership status request(s).");

        return self::SUCCESS;
    }
}
