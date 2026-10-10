<?php

namespace App\Services;

use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use Illuminate\Support\Collection;

class TransferCalendarAvailability
{
    public function __construct(private readonly TeacherBookingAvailability $availability) {}

    public function slots(ReservationRequest $reservation): Collection
    {
        $original = $reservation->lessonSlot;
        $minutes = (int) $original->starts_at->diffInMinutes($original->ends_at);
        $step = $original->venue?->termsRoomType() === 'music_room' ? 15 : 30;
        $limit = today()->addMonthNoOverflow()->endOfDay();
        $existing = LessonSlot::where('teacher_profile_id', $original->teacher_profile_id)
            ->where('ends_at', '>', today())->where('starts_at', '<=', $limit)
            ->with(['reservationRequests', 'trialLessonRequests', 'requestedTransferRequests'])->get()
            ->filter(fn ($slot) => $slot->status->value !== 'open'
                || $slot->reservationRequests->contains(fn ($r) => in_array($r->status->value, ['pending', 'approved'], true))
                || $slot->trialLessonRequests->contains(fn ($r) => in_array($r->status->value, ['pending', 'approved'], true))
                || $slot->requestedTransferRequests->contains(fn ($r) => $r->status->value === 'pending'));
        $candidates = collect();
        $id = -1;
        for ($day = today(); $day->lte($limit); $day->addDay()) {
            [$opening, $closing] = LessonOperatingHours::hours($day);
            for ($offset = 0; $offset < ($closing - $opening) * 60; $offset += $step) {
                $start = $day->copy()->setTime($opening, 0)->addMinutes($offset);
                $end = $start->copy()->addMinutes($minutes);
                if ($start->lte(now()) || $end->gt($day->copy()->setTime($closing, 0))) {
                    continue;
                }
                if ($existing->contains(function ($slot) use ($start, $end, $original) {
                    $buffer = $slot->venue_id !== null && $slot->venue_id === $original->venue_id ? 0 : TeacherBookingAvailability::TRAVEL_MINUTES;

                    return $start->lt($slot->ends_at->copy()->addMinutes($buffer))
                        && $end->gt($slot->starts_at->copy()->subMinutes($buffer));
                })) {
                    continue;
                }
                $slot = new LessonSlot([
                    'teacher_profile_id' => $original->teacher_profile_id,
                    'venue_id' => $original->venue_id, 'course_id' => $original->course_id,
                    'starts_at' => $start, 'ends_at' => $end, 'capacity' => 1,
                    'status' => 'open', 'booking_audience' => 'regular',
                ]);
                $slot->id = $id--;
                $slot->setRelation('venue', $original->venue);
                $slot->setRelation('teacherProfile', $original->teacherProfile);
                $slot->setRelation('course', $original->course);
                $slot->approved_reservations_count = 0;
                $candidates->push($slot);
            }
        }

        return $this->availability->availableSlots($candidates, $reservation->id);
    }
}
