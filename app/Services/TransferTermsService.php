<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TransferTermsService
{
    public function __construct(private readonly LessonPricingService $pricing) {}

    public function studioCancellationFee(LessonSlot $slot, CarbonImmutable $formalizedAt): ?int
    {
        $days = (int) $formalizedAt->startOfDay()->diffInDays(CarbonImmutable::instance($slot->starts_at)->startOfDay(), false);

        return match ($slot->venue?->termsRoomType()) {
            'music_room' => 0,
            'studio34' => $days >= 7 ? 0 : ($days >= 2 ? 805 : 1610),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function quote(TransferRequest $transfer, CarbonImmutable $formalizedAt, string $cause = 'student'): array
    {
        $original = $transfer->originalReservationRequest;
        $allowance = $this->annualAllowance($transfer->studentProfile, $formalizedAt);
        $used = TransferRequest::query()->where('student_profile_id', $transfer->student_profile_id)
            ->where('status', ApplicationStatus::Approved)
            ->where('reviewed_at', '>=', $allowance['year_start'])
            ->where('reviewed_at', '<', $allowance['year_start']->addYear())
            ->whereKeyNot($transfer->id)
            ->get(['terms_snapshot'])
            ->filter(fn ($item) => ($item->terms_snapshot['cause'] ?? 'student') !== 'teacher')->count();
        $days = (int) $formalizedAt->startOfDay()->diffInDays(CarbonImmutable::instance($original->lessonSlot->starts_at)->startOfDay(), false);
        $roomType = $original->lessonSlot->venue?->termsRoomType();
        $cancellation = $cause === 'teacher' ? 0 : $this->studioCancellationFee($original->lessonSlot, $formalizedAt);
        $lateFee = $cause === 'student' && $days < 4 ? 1000 : 0;
        if ($roomType === 'music_room' && $days <= 1) {
            $lateFee = 0;
        }
        if ($roomType === null && $cause === 'student' && $days <= 1) {
            $lateFee = null;
        }
        $excess = $cause === 'student' && $used >= $allowance['free_transfer_limit'];
        $excessLessonFee = 0;
        if ($excess) {
            $enrollment = $original->lessonEnrollment;
            $monthlyFee = $enrollment === null || $enrollment->student_profile_id !== $transfer->student_profile_id ? null : $this->pricing->forEnrollment($enrollment, $original->lessonSlot->starts_at)->lessonFeeTotal;
            $count = (int) ($enrollment?->monthly_lesson_limit ?? 0);
            $excessLessonFee = $monthlyFee !== null && $count > 0 && $monthlyFee % $count === 0
                ? intdiv($monthlyFee, $count) : null;
        }
        $components = [
            'studio_cancellation' => $cancellation,
            'late_transfer' => $lateFee,
            'excess_lesson' => $excessLessonFee,
            'excess_surcharge' => $excess ? 2000 : 0,
        ];

        return [
            'policy_version' => '2026-09-16',
            'billing_month' => CarbonImmutable::instance($original->lesson_entitlement_month ?? $original->lessonSlot->starts_at)->startOfMonth()->toDateString(),
            'formalized_at' => $formalizedAt->toIso8601String(),
            'cause' => $cause,
            'room_type' => $roomType,
            'year_start' => $allowance['year_start']->toDateString(),
            'free_transfer_limit' => $allowance['free_transfer_limit'],
            'used_before' => $used,
            'components' => $components,
            'requires_review' => in_array(null, $components, true) || $roomType === null || $cause === 'teacher',
            'lesson_settlement_review' => $cause === 'teacher',
            'total' => in_array(null, $components, true) ? null : array_sum($components),
        ];
    }

    public function assertEligible(ReservationRequest $original, LessonSlot $target, string $errorKey): void
    {
        if ($original->resultingTransferRequest()->where('status', ApplicationStatus::Approved)->exists()) {
            throw ValidationException::withMessages([$errorKey => '振替レッスンを再度振替することはできません。']);
        }

        $originalMonth = CarbonImmutable::instance($original->lessonSlot->starts_at)->startOfMonth();
        $targetMonth = CarbonImmutable::instance($target->starts_at)->startOfMonth();
        $membershipChange = $original->studentProfile->membershipStatusRequests()
            ->where('status', ApplicationStatus::Approved)
            ->whereIn('type', [MembershipRequestType::Pause, MembershipRequestType::Withdraw])
            ->where('effective_on', '>=', $originalMonth)
            ->where('effective_on', '<', $targetMonth->addMonth())
            ->exists();
        if ($membershipChange) {
            throw ValidationException::withMessages([$errorKey => '休会・退会月以降へ振替レッスンを繰り越すことはできません。']);
        }

        app(StudentLessonEligibility::class)->assertEligible($original->studentProfile, $target, $errorKey, exceptReservationId: $original->id);
        if ($original->lessonSlot->starts_at->diffInMinutes($original->lessonSlot->ends_at) !== $target->starts_at->diffInMinutes($target->ends_at)) {
            throw ValidationException::withMessages([$errorKey => '元のレッスンと同じ長さの振替先を選択してください。']);
        }

    }

    /** @return array{year_start: CarbonImmutable, year_end: CarbonImmutable, planned_lessons: int, free_transfer_limit: int} */
    public function annualAllowance(StudentProfile $student, CarbonImmutable $date): array
    {
        $yearStart = $date->setTimezone(config('app.timezone'))->startOfYear()->addMonths(3);
        if ($date->lt($yearStart)) {
            $yearStart = $yearStart->subYear();
        }
        $enrollments = $student->enrollments()->where('status', '!=', EnrollmentStatus::Pending)->get();
        $byId = $enrollments->keyBy('id');
        $membershipChanges = $student->membershipStatusRequests()
            ->where('status', ApplicationStatus::Approved)->orderBy('effective_on')->orderBy('id')->get();
        $planned = 0;

        for ($offset = 0; $offset < 12; $offset++) {
            $month = $yearStart->addMonths($offset);
            $latestChange = $membershipChanges->last(fn ($change) => $change->effective_on->lte($month->endOfMonth()));
            if ($latestChange !== null && in_array($latestChange->type, [MembershipRequestType::Pause, MembershipRequestType::Withdraw], true)) {
                continue;
            }
            $planned += $enrollments
                ->filter(fn (LessonEnrollment $enrollment) => $enrollment->starts_on->lte($month->endOfMonth())
                    && ($enrollment->ends_on === null || $enrollment->ends_on->gte($month))
                    && ! ($enrollment->status === EnrollmentStatus::Ended && $enrollment->ends_on === null))
                ->groupBy(fn (LessonEnrollment $enrollment) => $this->rootId($enrollment, $byId))
                ->sum(fn (Collection $versions) => (int) $versions->sortByDesc('starts_on')->first()->monthly_lesson_limit);
        }

        return [
            'year_start' => $yearStart,
            'year_end' => $yearStart->addYear()->subDay(),
            'planned_lessons' => $planned,
            'free_transfer_limit' => intdiv($planned, 4),
        ];
    }

    /** @param Collection<int, LessonEnrollment> $byId */
    private function rootId(LessonEnrollment $enrollment, Collection $byId): int
    {
        $seen = [];
        while ($enrollment->supersedes_lesson_enrollment_id !== null && ! isset($seen[$enrollment->id])) {
            $seen[$enrollment->id] = true;
            $parent = $byId->get($enrollment->supersedes_lesson_enrollment_id);
            if ($parent === null) {
                break;
            }
            $enrollment = $parent;
        }

        return $enrollment->id;
    }
}
