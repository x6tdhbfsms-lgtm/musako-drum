<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;
use App\Models\RegularScheduleOccurrence;
use App\Models\TeacherProfile;
use App\Models\Venue;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TeacherBookingAvailability
{
    public const TRAVEL_MINUTES = 15;

    public const TRAVEL_MESSAGE = '担当講師の会場移動に15分必要なため、この枠は予約できません。';

    public const OVERLAP_MESSAGE = '担当講師に同時間帯の予約があるため、この枠は予約できません。';

    public function assertAvailable(LessonSlot $slot, string $errorKey, ?int $exceptReservationId = null, ?int $exceptOccurrenceId = null): void
    {
        TeacherProfile::query()->lockForUpdate()->findOrFail($slot->teacher_profile_id);
        if ($slot->venue_id !== null) {
            Venue::query()->whereKey($slot->venue_id)->lockForUpdate()->firstOrFail();
        }
        $reason = $this->reasons(collect([$slot]), $exceptReservationId, true, $exceptOccurrenceId)[$slot->id ?? 'candidate'] ?? null;
        if ($reason !== null) {
            throw ValidationException::withMessages([$errorKey => $reason]);
        }
    }

    /** @param Collection<int, LessonSlot> $slots
     * @return Collection<int, LessonSlot>
     */
    public function availableSlots(Collection $slots, ?int $exceptReservationId = null): Collection
    {
        $reasons = $this->reasons($slots, $exceptReservationId);

        return $slots->reject(fn (LessonSlot $slot) => isset($reasons[$slot->id]))->values();
    }

    public function timingReason(LessonSlot $slot, bool $checkHours = true): ?string
    {
        $studio = $slot->venue?->termsRoomType() === 'studio34' || in_array($slot->venue?->code, config('musako.booking.studio_venue_codes', ['STUDIO34']), true)
            || mb_strtolower($slot->venue?->name ?? '') === 'studio34';
        if ($studio && (! in_array($slot->starts_at->minute, [0, 30], true) || $slot->starts_at->second !== 0)) {
            return 'studio34のレッスンは毎時00分・30分開始で選択してください。';
        }

        if ($studio && ($slot->booking_audience?->acceptsRegular() ?? true)
            && ! $slot->ends_at->equalTo($slot->starts_at->copy()->addHour())) {
            return 'studio34の通常レッスンは60分の枠を選択してください。';
        }

        if ($checkHours && ! LessonOperatingHours::allows($slot->starts_at, $slot->ends_at)) {
            return 'レッスンは'.LessonOperatingHours::DESCRIPTION.'の時間内に終了するよう設定してください。';
        }

        return null;
    }

    /** @param Collection<int, LessonSlot> $slots
     * @return array<int|string, string>
     */
    public function reasons(Collection $slots, ?int $exceptReservationId = null, bool $lock = false, ?int $exceptOccurrenceId = null): array
    {
        if ($slots->isEmpty()) {
            return [];
        }

        (new \Illuminate\Database\Eloquent\Collection($slots->all()))->loadMissing('venue');
        $neighbours = LessonSlot::query()
            ->where(fn ($query) => $query->whereIn('teacher_profile_id', $slots->pluck('teacher_profile_id')->unique())
                ->orWhereIn('venue_id', $slots->pluck('venue_id')->filter()->unique()))
            ->where('starts_at', '<', $slots->max('ends_at')->copy()->addMinutes(self::TRAVEL_MINUTES))
            ->where('ends_at', '>', $slots->min('starts_at')->copy()->subMinutes(self::TRAVEL_MINUTES))
            ->with([
                'reservationRequests' => fn ($query) => $query
                    ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Approved])
                    ->when($exceptReservationId !== null, fn ($query) => $query->whereKeyNot($exceptReservationId))
                    ->when($lock, fn ($query) => $query->lockForUpdate()),
                'trialLessonRequests' => fn ($query) => $query
                    ->whereIn('status', [TrialLessonStatus::Pending, TrialLessonStatus::Approved])
                    ->when($lock, fn ($query) => $query->lockForUpdate()),
                'requestedTransferRequests' => fn ($query) => $query
                    ->where('status', ApplicationStatus::Pending)
                    ->when($lock, fn ($query) => $query->lockForUpdate()),
            ])
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->filter(fn (LessonSlot $slot) => $slot->reservationRequests->isNotEmpty()
                || $slot->trialLessonRequests->isNotEmpty()
                || $slot->requestedTransferRequests->isNotEmpty());

        $holds = RegularScheduleOccurrence::query()
            ->whereIn('status', ['draft', 'conflict'])
            ->where(fn ($query) => $query->whereIn('teacher_profile_id', $slots->pluck('teacher_profile_id')->unique())
                ->orWhereIn('venue_id', $slots->pluck('venue_id')->filter()->unique()))
            ->when($exceptOccurrenceId !== null, fn ($query) => $query->whereKeyNot($exceptOccurrenceId))
            ->where('starts_at', '<', $slots->max('ends_at')->copy()->addMinutes(self::TRAVEL_MINUTES))
            ->where('ends_at', '>', $slots->min('starts_at')->copy()->subMinutes(self::TRAVEL_MINUTES))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
        $reasons = [];
        foreach ($slots as $slot) {
            $reason = $this->timingReason($slot);
            foreach ($neighbours as $other) {
                $sameTeacher = $slot->teacher_profile_id === $other->teacher_profile_id;
                $sameVenue = $slot->venue_id !== null && $slot->venue_id === $other->venue_id;
                if (! $sameTeacher && ! $sameVenue) {
                    continue;
                }
                $reusable = $sameTeacher && ! $slot->exists && $slot->venue_id === $other->venue_id
                    && $slot->course_id === $other->course_id
                    && $slot->starts_at->equalTo($other->starts_at) && $slot->ends_at->equalTo($other->ends_at);
                if (($slot->id !== null && $slot->id === $other->id) || $reusable) {
                    continue;
                }
                if ($slot->starts_at->lt($other->ends_at) && $slot->ends_at->gt($other->starts_at)) {
                    $reason = $sameTeacher ? self::OVERLAP_MESSAGE : '会場の同時間帯に別の予約があります。';
                    break;
                }
                $sameVenue = $slot->venue_id !== null && $slot->venue_id === $other->venue_id;
                if ($sameTeacher && ! $sameVenue
                    && $slot->starts_at->lt($other->ends_at->copy()->addMinutes(self::TRAVEL_MINUTES))
                    && $slot->ends_at->gt($other->starts_at->copy()->subMinutes(self::TRAVEL_MINUTES))) {
                    $reason = self::TRAVEL_MESSAGE;
                    break;
                }
            }
            foreach ($holds as $hold) {
                if ($slot->teacher_profile_id !== $hold->teacher_profile_id && ($slot->venue_id === null || $slot->venue_id !== $hold->venue_id)) {
                    continue;
                }
                $buffer = $slot->venue_id !== null && $slot->venue_id === $hold->venue_id ? 0 : self::TRAVEL_MINUTES;
                if ($slot->starts_at->lt($hold->ends_at->copy()->addMinutes($buffer))
                    && $slot->ends_at->gt($hold->starts_at->copy()->subMinutes($buffer))) {
                    $reason = '定期レッスンの仮押さえ（会場移動時間を含む）があるため予約できません。';
                    break;
                }
            }
            if ($reason !== null) {
                $reasons[$slot->id ?? 'candidate'] = $reason;
            }
        }

        return $reasons;
    }
}
