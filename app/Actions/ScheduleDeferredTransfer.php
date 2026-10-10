<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\LessonSlotAudience;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\LessonOperatingHours;
use App\Services\MusakoNotificationService;
use App\Services\TeacherBookingAvailability;
use App\Services\TransferTermsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleDeferredTransfer
{
    public function __construct(
        private readonly TeacherBookingAvailability $availability,
        private readonly TransferTermsService $terms,
        private readonly MusakoNotificationService $notifications,
    ) {}

    public function handle(TransferRequest $transfer, User $reviewer, CarbonImmutable $startsAt): TransferRequest
    {
        $scheduled = DB::transaction(function () use ($transfer, $reviewer, $startsAt): TransferRequest {
            StudentProfile::query()->lockForUpdate()->findOrFail($transfer->student_profile_id);
            $locked = TransferRequest::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! $locked->isAwaitingSchedule()) {
                throw ValidationException::withMessages(['starts_at' => 'この申請は日程保留中ではありません。すでに日程が確定していないか確認してください。']);
            }
            $original = ReservationRequest::query()->lockForUpdate()->findOrFail($locked->original_reservation_request_id);
            $source = $original->lessonSlot;
            TeacherProfile::query()->lockForUpdate()->findOrFail($source->teacher_profile_id);
            $endsAt = $startsAt->addMinutes((int) $source->starts_at->diffInMinutes($source->ends_at));
            if (! $startsAt->isFuture() || ! LessonOperatingHours::allows($startsAt, $endsAt)
                || $startsAt->minute % 15 !== 0 || $startsAt->second !== 0) {
                throw ValidationException::withMessages(['starts_at' => '今後の日付で、平日12〜21時・土日10〜20時に収まる15分単位の開始時刻を選択してください。studio34は00分・30分開始です。']);
            }
            if ($original->status !== ReservationStatus::Cancelled
                || ! isset($locked->terms_snapshot['deferred_studio_fee_amount'], $locked->terms_snapshot['deferred_studio_fee_priced_on'])) {
                throw ValidationException::withMessages(['starts_at' => '保留時の予約・料金記録を管理者が確認する必要があります。']);
            }
            if (LessonSlot::query()->where('teacher_profile_id', $source->teacher_profile_id)
                ->where('status', LessonSlotStatus::Closed)->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'この時間帯には受付停止の枠があります。']);
            }
            $slot = LessonSlot::create([
                'teacher_profile_id' => $source->teacher_profile_id, 'venue_id' => $source->venue_id,
                'course_id' => $source->course_id, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
                'capacity' => 1, 'status' => LessonSlotStatus::Open, 'booking_audience' => LessonSlotAudience::Regular,
            ]);
            $this->terms->assertEligible($original, $slot, 'starts_at');
            $this->availability->assertAvailable($slot, 'starts_at');
            $reservation = ReservationRequest::create([
                'student_profile_id' => $locked->student_profile_id, 'lesson_slot_id' => $slot->id,
                'lesson_enrollment_id' => $original->lesson_enrollment_id,
                'lesson_entitlement_month' => ($original->lesson_entitlement_month ?? $source->starts_at->copy()->startOfMonth())->toDateString(),
                'studio_fee_amount' => $locked->terms_snapshot['deferred_studio_fee_amount'],
                'studio_fee_priced_on' => $locked->terms_snapshot['deferred_studio_fee_priced_on'],
                'status' => ReservationStatus::Approved, 'requested_at' => $locked->requested_at,
                'reviewed_by_user_id' => $reviewer->id, 'reviewed_at' => now(),
                'student_note' => $locked->student_note, 'staff_note' => '保留していた振替の日程を確定しました。',
            ]);
            $locked->update(['requested_lesson_slot_id' => $slot->id, 'resulting_reservation_request_id' => $reservation->id]);

            return $locked->refresh();
        }, 3);
        $this->notifications->transferReviewed($scheduled, ApplicationStatus::Approved);

        return $scheduled;
    }
}
