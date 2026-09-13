<?php

namespace App\Actions;

use App\Enums\LessonSlotAudience;
use App\Enums\LessonSlotStatus;
use App\Enums\RegularScheduleBatchStatus;
use App\Enums\RegularScheduleOccurrenceStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\RegularScheduleAudit;
use App\Models\RegularScheduleBatch;
use App\Models\RegularScheduleOccurrence;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\LessonPricingService;
use App\Services\MusakoNotificationService;
use App\Services\RegularScheduleConflictDetector;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmRegularScheduleOccurrences
{
    public function __construct(
        private readonly RegularScheduleConflictDetector $conflicts,
        private readonly MonthlyLessonUsageCalculator $monthlyUsage,
        private readonly LessonPricingService $pricing,
        private readonly MusakoNotificationService $notifications,
    ) {}

    /** @param list<int> $occurrenceIds
     * @return Collection<int, RegularScheduleOccurrence>
     */
    public function handle(array $occurrenceIds, User $actor, bool $skipConflicts = false): Collection
    {
        $confirmed = DB::transaction(function () use ($occurrenceIds, $actor, $skipConflicts): Collection {
            $items = RegularScheduleOccurrence::query()->whereKey($occurrenceIds)->orderBy('starts_at')->lockForUpdate()->get();
            $confirmed = collect();

            foreach ($items as $occurrence) {
                if ($occurrence->status === RegularScheduleOccurrenceStatus::Confirmed) {
                    $confirmed->push($occurrence);

                    continue;
                }
                if (! in_array($occurrence->status, [RegularScheduleOccurrenceStatus::Draft, RegularScheduleOccurrenceStatus::Conflict], true)) {
                    continue;
                }

                $reasons = $this->conflicts->detect($occurrence);
                if ($reasons !== []) {
                    $occurrence->update(['status' => RegularScheduleOccurrenceStatus::Conflict, 'conflict_reasons' => $reasons]);
                    if ($skipConflicts) {
                        continue;
                    }
                    throw ValidationException::withMessages(['occurrences' => $occurrence->starts_at->format('n/j H:i').' は競合しているため確定できません。']);
                }

                $student = StudentProfile::query()->lockForUpdate()->findOrFail($occurrence->student_profile_id);
                $summary = $this->monthlyUsage->calculate($student, CarbonImmutable::instance($occurrence->lesson_entitlement_month));
                if ($summary->used >= $summary->contracted) {
                    throw ValidationException::withMessages(['occurrences' => $student->user->name.'さんは対象月の契約回数上限に達しています。']);
                }

                $slot = LessonSlot::query()
                    ->where('teacher_profile_id', $occurrence->teacher_profile_id)
                    ->where('venue_id', $occurrence->venue_id)
                    ->where('course_id', $occurrence->course_id)
                    ->where('starts_at', $occurrence->starts_at)
                    ->where('ends_at', $occurrence->ends_at)
                    ->lockForUpdate()->first();
                if ($slot === null) {
                    $slot = LessonSlot::query()->create([
                        'teacher_profile_id' => $occurrence->teacher_profile_id,
                        'venue_id' => $occurrence->venue_id,
                        'course_id' => $occurrence->course_id,
                        'starts_at' => $occurrence->starts_at,
                        'ends_at' => $occurrence->ends_at,
                        'capacity' => 1,
                        'status' => LessonSlotStatus::Open,
                        'booking_audience' => LessonSlotAudience::Regular,
                        'notes' => 'レギュラー固定スケジュールから生成',
                    ]);
                }

                $quote = $this->pricing->forEnrollment($occurrence->lessonEnrollment, $occurrence->starts_at);
                ReservationRequest::query()->create([
                    'student_profile_id' => $occurrence->student_profile_id,
                    'lesson_slot_id' => $slot->id,
                    'lesson_enrollment_id' => $occurrence->lesson_enrollment_id,
                    'regular_schedule_occurrence_id' => $occurrence->id,
                    'status' => ReservationStatus::Approved,
                    'requested_at' => now(),
                    'lesson_entitlement_month' => $occurrence->lesson_entitlement_month,
                    'studio_fee_amount' => $quote->studioFeePerLesson,
                    'studio_fee_priced_on' => $occurrence->starts_at->toDateString(),
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'staff_note' => 'レギュラー固定スケジュール確定',
                ]);
                $occurrence->update([
                    'status' => RegularScheduleOccurrenceStatus::Confirmed,
                    'conflict_reasons' => null,
                    'confirmed_by_user_id' => $actor->id,
                    'confirmed_at' => now(),
                ]);
                RegularScheduleAudit::query()->create([
                    'regular_schedule_batch_id' => $occurrence->regular_schedule_batch_id,
                    'regular_schedule_occurrence_id' => $occurrence->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'confirmed',
                    'after_values' => $occurrence->fresh()->toArray(),
                    'created_at' => now(),
                ]);
                $confirmed->push($occurrence->fresh());
            }

            foreach ($items->pluck('regular_schedule_batch_id')->unique() as $batchId) {
                $batch = RegularScheduleBatch::query()->lockForUpdate()->findOrFail($batchId);
                $hasConflict = $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Conflict)->exists();
                $hasDraft = $batch->occurrences()->where('status', RegularScheduleOccurrenceStatus::Draft)->exists();
                $batch->update([
                    'status' => $hasConflict ? RegularScheduleBatchStatus::Conflict : ($hasDraft ? RegularScheduleBatchStatus::Draft : RegularScheduleBatchStatus::Confirmed),
                    'confirmed_at' => ! $hasConflict && ! $hasDraft ? now() : null,
                ]);
            }

            return $confirmed;
        }, 3);

        foreach ($confirmed->groupBy(fn (RegularScheduleOccurrence $item) => $item->student_profile_id.'-'.$item->lesson_entitlement_month->format('Y-m')) as $items) {
            $this->notifications->regularScheduleConfirmed(
                $items->first()->studentProfile,
                CarbonImmutable::instance($items->first()->lesson_entitlement_month),
            );
        }

        return $confirmed;
    }
}
