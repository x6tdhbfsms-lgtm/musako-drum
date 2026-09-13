<?php

namespace App\Actions;

use App\Enums\RegularScheduleBatchStatus;
use App\Enums\RegularScheduleOccurrenceStatus;
use App\Enums\ReservationStatus;
use App\Models\RegularScheduleAudit;
use App\Models\RegularScheduleBatch;
use App\Models\RegularScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeRegularScheduleOccurrenceStatus
{
    public function handle(RegularScheduleOccurrence $occurrence, User $actor, RegularScheduleOccurrenceStatus $status, ?string $reason): RegularScheduleOccurrence
    {
        return DB::transaction(function () use ($occurrence, $actor, $status, $reason): RegularScheduleOccurrence {
            $occurrence = RegularScheduleOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            $before = $occurrence->toArray();

            if ($status === RegularScheduleOccurrenceStatus::Skipped) {
                if (! in_array($occurrence->status, [RegularScheduleOccurrenceStatus::Draft, RegularScheduleOccurrenceStatus::Conflict], true)) {
                    throw ValidationException::withMessages(['occurrence' => '下書きまたは競合中の予定だけスキップできます。']);
                }
            } elseif ($status === RegularScheduleOccurrenceStatus::Cancelled) {
                if ($occurrence->status !== RegularScheduleOccurrenceStatus::Confirmed) {
                    throw ValidationException::withMessages(['occurrence' => '確定済みの予定だけ取り消せます。']);
                }
                $reservation = $occurrence->reservationRequest()->lockForUpdate()->firstOrFail();
                $reservation->update([
                    'status' => ReservationStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason ?: 'スタッフによる定期予定の取消',
                ]);
            } else {
                throw ValidationException::withMessages(['occurrence' => '変更先の状態が不正です。']);
            }

            $occurrence->update(['status' => $status]);
            RegularScheduleAudit::query()->create([
                'regular_schedule_batch_id' => $occurrence->regular_schedule_batch_id,
                'regular_schedule_occurrence_id' => $occurrence->id,
                'actor_user_id' => $actor->id,
                'action' => $status === RegularScheduleOccurrenceStatus::Skipped ? 'skipped' : 'cancelled',
                'before_values' => $before,
                'after_values' => [...$occurrence->fresh()->toArray(), 'reason' => $reason],
                'created_at' => now(),
            ]);

            $batch = RegularScheduleBatch::query()->lockForUpdate()->findOrFail($occurrence->regular_schedule_batch_id);
            $generated = $batch->occurrences()->whereNotIn('status', [RegularScheduleOccurrenceStatus::Skipped, RegularScheduleOccurrenceStatus::Cancelled])->count();
            $hasConflict = $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Conflict)->exists();
            $hasDraft = $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Draft)->exists();
            $batch->update([
                'generated_count' => $generated,
                'warning' => $generated < $batch->expected_count ? "月{$batch->expected_count}回契約ですが{$generated}回のみ有効です。スキップ・取消内容を確認してください。" : null,
                'status' => $hasConflict
                    ? RegularScheduleBatchStatus::Conflict
                    : ($hasDraft ? RegularScheduleBatchStatus::Draft : ($generated === 0 ? RegularScheduleBatchStatus::Skipped : RegularScheduleBatchStatus::Confirmed)),
            ]);

            return $occurrence->fresh();
        }, 3);
    }
}
