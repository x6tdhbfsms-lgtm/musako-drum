<?php

namespace App\Actions;

use App\Enums\RegularScheduleBatchStatus;
use App\Enums\RegularScheduleOccurrenceStatus;
use App\Models\RegularScheduleAudit;
use App\Models\RegularScheduleBatch;
use App\Models\RegularScheduleOccurrence;
use App\Models\User;
use App\Services\RegularScheduleConflictDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRegularScheduleOccurrence
{
    public function __construct(private readonly RegularScheduleConflictDetector $conflicts) {}

    /** @param array{starts_at: string, teacher_profile_id: int, venue_id?: int|null} $attributes */
    public function handle(RegularScheduleOccurrence $occurrence, User $actor, array $attributes): RegularScheduleOccurrence
    {
        return DB::transaction(function () use ($occurrence, $actor, $attributes): RegularScheduleOccurrence {
            $occurrence = RegularScheduleOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            if (! in_array($occurrence->status, [RegularScheduleOccurrenceStatus::Draft, RegularScheduleOccurrenceStatus::Conflict], true)) {
                throw ValidationException::withMessages(['occurrence' => '確定済みの予定は直接編集できません。振替または管理変更を利用してください。']);
            }

            $before = $occurrence->toArray();
            $startsAt = CarbonImmutable::parse($attributes['starts_at'], config('app.timezone'));
            $minutes = (int) ($occurrence->lessonEnrollment->lesson_minutes ?? $occurrence->starts_at->diffInMinutes($occurrence->ends_at));
            $occurrence->update([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($minutes),
                'teacher_profile_id' => $attributes['teacher_profile_id'],
                'venue_id' => $attributes['venue_id'] ?? null,
                'manually_adjusted' => true,
            ]);

            $reasons = $this->conflicts->detect($occurrence->fresh());
            $occurrence->update([
                'status' => $reasons === [] ? RegularScheduleOccurrenceStatus::Draft : RegularScheduleOccurrenceStatus::Conflict,
                'conflict_reasons' => $reasons ?: null,
            ]);
            RegularScheduleAudit::query()->create([
                'regular_schedule_batch_id' => $occurrence->regular_schedule_batch_id,
                'regular_schedule_occurrence_id' => $occurrence->id,
                'actor_user_id' => $actor->id,
                'action' => 'edited',
                'before_values' => $before,
                'after_values' => $occurrence->fresh()->toArray(),
                'created_at' => now(),
            ]);
            $this->refreshBatch($occurrence->regular_schedule_batch_id);

            return $occurrence->fresh();
        }, 3);
    }

    private function refreshBatch(int $batchId): void
    {
        $batch = RegularScheduleBatch::query()->lockForUpdate()->findOrFail($batchId);
        $batch->update(['status' => $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Conflict)->exists()
            ? RegularScheduleBatchStatus::Conflict
            : RegularScheduleBatchStatus::Draft]);
    }
}
