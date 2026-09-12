<?php

namespace App\Console\Commands;

use App\Actions\ApplyApprovedMembershipStatusRequest;
use App\Enums\ApplicationStatus;
use App\Models\MembershipStatusRequest;
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
    public function handle(ApplyApprovedMembershipStatusRequest $applyApprovedRequest): int
    {
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
