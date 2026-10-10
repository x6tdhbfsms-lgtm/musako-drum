<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;

class PausedScheduleRelease
{
    public function release(StudentProfile $student): void
    {
        DB::transaction(function () use ($student): void {
            StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $latest = $student->membershipStatusRequests()
                ->where('status', ApplicationStatus::Approved)->whereNotNull('applied_at')
                ->where('effective_on', '<=', today())->orderByDesc('effective_on')->orderByDesc('id')
                ->lockForUpdate()->first();
            if ($latest === null || $latest->type !== MembershipRequestType::Pause
                || $latest->schedule_released_at !== null
                || $latest->effective_on->copy()->startOfMonth()->addMonths(2)->gt(today())) {
                return;
            }
            $enrollments = $student->enrollments()->where('status', EnrollmentStatus::Paused)->orderBy('id')->lockForUpdate()->get();
            if ($enrollments->isEmpty()) {
                return;
            }
            $snapshot = [];
            foreach ($enrollments as $enrollment) {
                $snapshot[$enrollment->id] = $enrollment->only(['weekday', 'starts_at_time', 'regular_week_numbers']);
                $enrollment->update(['weekday' => null, 'starts_at_time' => null, 'regular_week_numbers' => null]);
            }
            $latest->update(['released_schedule_snapshot' => $snapshot, 'schedule_released_at' => now()]);
        }, 3);
    }
}
