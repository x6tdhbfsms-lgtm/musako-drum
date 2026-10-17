<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceNoticeType;
use App\Enums\LessonType;
use App\Enums\MembershipRequestType;
use App\Enums\ReservationStatus;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;

class FlexInactivityWarning
{
    public function appliesTo(StudentProfile $student): bool
    {
        $now = CarbonImmutable::now('Asia/Tokyo');
        $today = $now->startOfDay();
        $history = $student->enrollments()->get()->keyBy('id');
        $active = $student->enrollments()->activeOn($today)->where('lesson_type', LessonType::Flex)->get();
        if ($active->isEmpty()) {
            return false;
        }
        $resumptions = $student->membershipStatusRequests()
            ->where('type', MembershipRequestType::Resume)
            ->where('status', ApplicationStatus::Approved)
            ->whereNotNull('applied_at')->whereDate('effective_on', '<=', $today)->get();
        $lessons = $student->reservationRequests()->where('status', ReservationStatus::Approved)
            ->whereHas('lessonSlot', fn ($slots) => $slots->where('starts_at', '<=', $now))
            ->with(['lessonSlot', 'attendanceNotice', 'transferRequests'])->get();

        foreach ($active as $enrollment) {
            $current = $enrollment;
            $ids = [$current->id];
            while ($parent = $history->get($current->supersedes_lesson_enrollment_id)) {
                if (in_array($parent->id, $ids, true) || $parent->lesson_type !== LessonType::Flex
                    || $parent->ends_on === null || ! $parent->ends_on->copy()->addDay()->equalTo($current->starts_on)) {
                    break;
                }
                $ids[] = $parent->id;
                $current = $parent;
            }
            $baseline = CarbonImmutable::parse($current->starts_on->toDateString(), 'Asia/Tokyo');
            foreach ($resumptions as $resume) {
                // Only a recorded application to this contract chain establishes its restart.
                if (array_intersect($ids, array_keys($resume->previous_enrollment_statuses ?? []))) {
                    $baseline = $baseline->max(CarbonImmutable::parse($resume->effective_on->toDateString(), 'Asia/Tokyo'));
                }
            }
            foreach ($lessons as $lesson) {
                // Older reservations may lack an enrollment link. Only use an
                // unambiguous contract match valid on the actual lesson date.
                $lessonEnrollmentId = $lesson->lesson_enrollment_id;
                if ($lessonEnrollmentId === null) {
                    $date = $lesson->lessonSlot->starts_at->toDateString();
                    $matches = $history->filter(fn ($version) => $version->course_id === $lesson->lessonSlot->course_id
                        && $version->starts_on->toDateString() <= $date
                        && ($version->ends_on === null || $version->ends_on->toDateString() >= $date));
                    $lessonEnrollmentId = $matches->count() === 1 ? $matches->first()->id : null;
                }
                if (! in_array($lessonEnrollmentId, $ids, true)
                    || $lesson->attendanceNotice?->type === AttendanceNoticeType::Absence
                    || $lesson->transferRequests->contains(fn ($transfer) => $transfer->isAwaitingSchedule()
                        || ($transfer->status === ApplicationStatus::Approved && $transfer->resulting_reservation_request_id !== null))) {
                    continue;
                }
                if ($lesson->lessonSlot->ends_at->greaterThan($now)
                    && ($lesson->completed_at === null || $lesson->completed_at->greaterThan($now))) {
                    continue;
                }
                $date = CarbonImmutable::instance($lesson->lessonSlot->starts_at)->setTimezone('Asia/Tokyo')->startOfDay();
                $baseline = $baseline->max($date);
            }
            if ($today->greaterThanOrEqualTo($baseline->addMonthsNoOverflow(2))) {
                return true;
            }
        }

        return false;
    }
}
