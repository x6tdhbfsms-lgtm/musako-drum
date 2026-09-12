<?php

namespace App\Actions;

use App\Enums\AttendanceNoticeType;
use App\Enums\ReservationStatus;
use App\Models\AttendanceNotice;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertAttendanceNotice
{
    public function handle(
        StudentProfile $studentProfile,
        ReservationRequest $reservationRequest,
        AttendanceNoticeType $type,
        ?int $lateMinutes,
        ?string $expectedArrivalTime,
        ?string $notes,
    ): AttendanceNotice {
        return DB::transaction(function () use ($studentProfile, $reservationRequest, $type, $lateMinutes, $expectedArrivalTime, $notes): AttendanceNotice {
            $lockedReservation = ReservationRequest::query()
                ->with('lessonSlot')
                ->lockForUpdate()
                ->findOrFail($reservationRequest->id);

            if ($lockedReservation->student_profile_id !== $studentProfile->id
                || $lockedReservation->status !== ReservationStatus::Approved
                || ! $lockedReservation->lessonSlot->ends_at->isFuture()) {
                throw ValidationException::withMessages(['reservation' => 'この予約にはお休み・遅刻連絡を登録できません。']);
            }

            $expectedArrivalAt = $type === AttendanceNoticeType::Late && $expectedArrivalTime !== null
                ? CarbonImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $lockedReservation->lessonSlot->starts_at->format('Y-m-d').' '.$expectedArrivalTime,
                    config('app.timezone'),
                )
                : null;

            if ($expectedArrivalAt !== null
                && ($expectedArrivalAt->lessThanOrEqualTo($lockedReservation->lessonSlot->starts_at)
                    || $expectedArrivalAt->greaterThan($lockedReservation->lessonSlot->ends_at))) {
                throw ValidationException::withMessages(['expected_arrival_time' => '到着予定時刻はレッスン開始後から終了時刻までの範囲で入力してください。']);
            }

            return AttendanceNotice::query()->updateOrCreate(
                ['reservation_request_id' => $lockedReservation->id],
                [
                    'type' => $type,
                    'late_minutes' => $type === AttendanceNoticeType::Late ? $lateMinutes : null,
                    'expected_arrival_at' => $expectedArrivalAt,
                    'notes' => $notes,
                    'submitted_at' => now(),
                ],
            );
        }, 3);
    }
}
