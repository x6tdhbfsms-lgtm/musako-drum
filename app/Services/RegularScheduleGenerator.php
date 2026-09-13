<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\MembershipRequestType;
use App\Enums\RegularScheduleBatchStatus;
use App\Enums\RegularScheduleOccurrenceStatus;
use App\Enums\UserRole;
use App\Models\LessonEnrollment;
use App\Models\MembershipStatusRequest;
use App\Models\RegularScheduleAudit;
use App\Models\RegularScheduleBatch;
use App\Models\RegularScheduleOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RegularScheduleGenerator
{
    public function __construct(private readonly RegularScheduleConflictDetector $conflicts) {}

    /** @return Collection<int, RegularScheduleBatch> */
    public function generateMonth(CarbonImmutable $month, ?User $actor = null): Collection
    {
        $month = $month->setTimezone(config('app.timezone'))->startOfMonth();

        $enrollments = LessonEnrollment::query()
            ->with(['studentProfile.user', 'teacherProfile', 'venue', 'course'])
            ->where('lesson_type', LessonType::Regular)
            ->where('status', EnrollmentStatus::Active)
            ->whereDate('starts_on', '<=', $month->endOfMonth())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $month))
            ->whereNotNull(['teacher_profile_id', 'weekday', 'starts_at_time', 'lesson_minutes'])
            ->when($actor?->role === UserRole::Teacher, fn (Builder $query) => $query->where('teacher_profile_id', $actor->teacherProfile?->id ?? 0))
            ->orderBy('id')->get();

        $batches = $enrollments
            ->groupBy(fn (LessonEnrollment $enrollment): int => $this->rootEnrollmentId($enrollment))
            ->map(function (Collection $versions) use ($month, $actor): RegularScheduleBatch {
                $representative = $versions->sortByDesc('starts_on')->first();

                return $this->generateEnrollment($representative, $month, $actor, $versions);
            })->values();

        RegularScheduleOccurrence::query()
            ->whereDate('lesson_entitlement_month', $month)
            ->whereIn('status', [RegularScheduleOccurrenceStatus::Draft, RegularScheduleOccurrenceStatus::Conflict])
            ->when($actor?->role === UserRole::Teacher, fn (Builder $query) => $query->where('teacher_profile_id', $actor->teacherProfile?->id ?? 0))
            ->get()->each(function (RegularScheduleOccurrence $occurrence): void {
                $reasons = $this->conflicts->detect($occurrence);
                $occurrence->update([
                    'status' => $reasons === [] ? RegularScheduleOccurrenceStatus::Draft : RegularScheduleOccurrenceStatus::Conflict,
                    'conflict_reasons' => $reasons ?: null,
                ]);
            });

        foreach ($batches as $batch) {
            $hasConflict = $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Conflict)->exists();
            $hasDraft = $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Draft)->exists();
            $batch->update(['status' => $hasConflict
                ? RegularScheduleBatchStatus::Conflict
                : ($hasDraft || $batch->warning ? RegularScheduleBatchStatus::Draft : RegularScheduleBatchStatus::Confirmed)]);
        }

        return $batches->map->fresh(['occurrences']);
    }

    /** @param Collection<int, LessonEnrollment>|null $versions */
    public function generateEnrollment(LessonEnrollment $enrollment, CarbonImmutable $month, ?User $actor = null, ?Collection $versions = null): RegularScheduleBatch
    {
        $month = $month->setTimezone(config('app.timezone'))->startOfMonth();

        return DB::transaction(function () use ($enrollment, $month, $actor, $versions): RegularScheduleBatch {
            $enrollment = LessonEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $versions = ($versions ?? collect([$enrollment]))->map(fn (LessonEnrollment $version) => LessonEnrollment::query()->lockForUpdate()->findOrFail($version->id));
            $expected = max(0, (int) $enrollment->monthly_lesson_limit);
            $batch = RegularScheduleBatch::query()
                ->whereIn('lesson_enrollment_id', $versions->pluck('id')->all())
                ->whereDate('entitlement_month', $month)
                ->first();
            $isRegeneration = $batch !== null;
            if ($batch === null) {
                $batch = RegularScheduleBatch::query()->create([
                    'lesson_enrollment_id' => $enrollment->id,
                    'entitlement_month' => $month->toDateString(),
                    'expected_count' => $expected,
                    'generated_count' => 0,
                    'status' => RegularScheduleBatchStatus::Draft,
                    'generated_by_user_id' => $actor?->id,
                    'generated_at' => now(),
                ]);
            }
            $batch = RegularScheduleBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->lesson_enrollment_id !== $enrollment->id) {
                $batch->update(['lesson_enrollment_id' => $enrollment->id]);
            }

            $selected = collect();
            foreach ($versions as $version) {
                $dates = $this->weekdayDates($month, (int) $version->weekday);
                foreach ($this->normalizedWeeks($version, (int) $version->monthly_lesson_limit) as $week) {
                    $date = $dates[$week - 1] ?? null;
                    if ($date !== null && $date->greaterThanOrEqualTo($version->starts_on) && ($version->ends_on === null || $date->lessThanOrEqualTo($version->ends_on))) {
                        $selected->push(['version' => $version, 'week' => $week, 'date' => $date]);
                    }
                }
            }
            $selected = $selected->sortBy('date')->take($expected);

            foreach ($selected as $candidate) {
                /** @var LessonEnrollment $version */
                $version = $candidate['version'];
                $week = $candidate['week'];
                $date = $candidate['date'];
                if ($this->isInactiveOn($version, $date)) {
                    continue;
                }

                $startsAt = CarbonImmutable::parse($date->toDateString().' '.$version->starts_at_time, config('app.timezone'));
                $occurrence = RegularScheduleOccurrence::query()->firstOrCreate(
                    ['regular_schedule_batch_id' => $batch->id, 'source_key' => $version->id.'-week-'.$week],
                    [
                        'lesson_enrollment_id' => $version->id,
                        'student_profile_id' => $version->student_profile_id,
                        'teacher_profile_id' => $version->teacher_profile_id,
                        'venue_id' => $version->venue_id,
                        'course_id' => $version->course_id,
                        'week_number' => $week,
                        'starts_at' => $startsAt,
                        'ends_at' => $startsAt->addMinutes((int) $version->lesson_minutes),
                        'lesson_entitlement_month' => $month->toDateString(),
                        'status' => RegularScheduleOccurrenceStatus::Draft,
                    ],
                );

                if (! $occurrence->wasRecentlyCreated && ($occurrence->manually_adjusted || in_array($occurrence->status, [RegularScheduleOccurrenceStatus::Confirmed, RegularScheduleOccurrenceStatus::Skipped, RegularScheduleOccurrenceStatus::Cancelled], true))) {
                    continue;
                }

                $reasons = $this->conflicts->detect($occurrence);
                $occurrence->update([
                    'status' => $reasons === [] ? RegularScheduleOccurrenceStatus::Draft : RegularScheduleOccurrenceStatus::Conflict,
                    'conflict_reasons' => $reasons ?: null,
                ]);
                if ($occurrence->wasRecentlyCreated) {
                    $this->audit($batch, $occurrence, $actor, 'generated', null, $occurrence->fresh()->toArray());
                }
            }

            $generated = $batch->occurrences()->whereNotIn('status', [RegularScheduleOccurrenceStatus::Skipped, RegularScheduleOccurrenceStatus::Cancelled])->count();
            $warning = $generated < $expected
                ? "月{$expected}回契約ですが{$generated}回しか候補を生成できません。開始日・終了日・休会予定・週パターンを確認してください。"
                : null;
            $status = $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Conflict)->exists()
                ? RegularScheduleBatchStatus::Conflict
                : ($generated < $expected || $batch->occurrences()->whereNot('status', RegularScheduleOccurrenceStatus::Confirmed)->exists()
                    ? RegularScheduleBatchStatus::Draft
                    : RegularScheduleBatchStatus::Confirmed);
            $batch->update([
                'expected_count' => $expected,
                'generated_count' => $generated,
                'status' => $status,
                'warning' => $warning,
                'generated_by_user_id' => $batch->generated_by_user_id ?? $actor?->id,
                'generated_at' => now(),
            ]);
            if ($isRegeneration) {
                $this->audit($batch, null, $actor, 'regenerated', null, [
                    'generated_count' => $generated,
                    'warning' => $warning,
                ]);
            }

            return $batch->fresh(['occurrences']);
        }, 3);
    }

    /** @return list<int> */
    private function normalizedWeeks(LessonEnrollment $enrollment, int $expected): array
    {
        $weeks = is_array($enrollment->regular_week_numbers) ? $enrollment->regular_week_numbers : range(1, $expected);
        $weeks = array_values(array_unique(array_map('intval', $weeks)));
        $weeks = array_values(array_filter($weeks, fn (int $week) => $week >= 1 && $week <= 5));

        return array_slice($weeks, 0, $expected);
    }

    /** @return list<CarbonImmutable> */
    private function weekdayDates(CarbonImmutable $month, int $weekday): array
    {
        $dates = [];
        for ($date = $month; $date->isSameMonth($month); $date = $date->addDay()) {
            if ($date->dayOfWeek === $weekday) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    private function isInactiveOn(LessonEnrollment $enrollment, CarbonImmutable $date): bool
    {
        if ($enrollment->studentProfile->user->account_status?->value === 'withdrawn') {
            return true;
        }

        return MembershipStatusRequest::query()
            ->where('student_profile_id', $enrollment->student_profile_id)
            ->where('status', ApplicationStatus::Approved)
            ->whereIn('type', [MembershipRequestType::Pause->value, MembershipRequestType::Withdraw->value])
            ->whereDate('effective_on', '<=', $date)
            ->exists();
    }

    private function rootEnrollmentId(LessonEnrollment $enrollment): int
    {
        $current = $enrollment;
        $visited = [];
        while ($current->supersedes_lesson_enrollment_id !== null && ! isset($visited[$current->id])) {
            $visited[$current->id] = true;
            $current = $current->supersededEnrollment()->first() ?? $current;
            if (isset($visited[$current->id])) {
                break;
            }
        }

        return $current->id;
    }

    private function audit(RegularScheduleBatch $batch, ?RegularScheduleOccurrence $occurrence, ?User $actor, string $action, ?array $before, ?array $after): void
    {
        RegularScheduleAudit::query()->create([
            'regular_schedule_batch_id' => $batch->id,
            'regular_schedule_occurrence_id' => $occurrence?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'created_at' => now(),
        ]);
    }
}
