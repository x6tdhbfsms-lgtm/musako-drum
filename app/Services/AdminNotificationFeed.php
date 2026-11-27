<?php

namespace App\Services;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\AdmissionApplication;
use App\Models\ContractChangeRequest;
use App\Models\Inquiry;
use App\Models\LessonEnrollment;
use App\Models\MembershipStatusRequest;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\ReservationRequest;
use App\Models\TransferRequest;
use App\Models\TrialLessonRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AdminNotificationFeed
{
    public function items(): Collection
    {
        $items = collect();

        $this->append($items, AdmissionApplication::query()
            ->where('status', AdmissionApplicationStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (AdmissionApplication $request): array => $this->item('入会申請', $request->name, '新しい入会申請があります。', $request->requested_at, route('staff.admission-applications.show', $request), 'request'));

        $this->append($items, TrialLessonRequest::query()
            ->where('status', TrialLessonStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (TrialLessonRequest $request): array => $this->item('体験レッスン申請', $request->name, '体験レッスンの申請があります。', $request->requested_at, route('staff.trial-lessons.show', $request), 'request'));

        $this->append($items, ReservationRequest::query()
            ->with(['studentProfile.user', 'lessonSlot'])
            ->where('status', ReservationStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (ReservationRequest $request): array => $this->item('予約申請', $request->studentProfile->user->name, $request->lessonSlot->starts_at->format('n月j日 H:i').'の予約申請があります。', $request->requested_at, route('staff.reservations.show', $request), 'request'));

        $this->append($items, TransferRequest::query()
            ->with(['studentProfile.user'])
            ->where('status', ApplicationStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (TransferRequest $request): array => $this->item('振替申請', $request->studentProfile->user->name, $request->requested_lesson_slot_id ? '振替先の確認が必要です。' : '振替日保留の申請があります。', $request->requested_at, route('staff.transfer-requests.show', $request), 'request'));

        $this->append($items, MembershipStatusRequest::query()
            ->with(['studentProfile.user'])
            ->where('status', ApplicationStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (MembershipStatusRequest $request): array => $this->item('在籍手続き', $request->studentProfile->user->name, ['pause' => '休会', 'withdraw' => '退会', 'resume' => '再開'][$request->type->value].'申請の確認が必要です。', $request->requested_at, route('staff.membership-status-requests.show', $request), 'request'));

        $this->append($items, ContractChangeRequest::query()
            ->with(['studentProfile.user'])
            ->where('status', ApplicationStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (ContractChangeRequest $request): array => $this->item('契約変更', $request->studentProfile->user->name, 'コース・回数などの変更申請があります。', $request->requested_at, route('staff.contract-change-requests.show', $request), 'request'));

        $this->append($items, PersonalInformationChangeRequest::query()
            ->with(['studentProfile.user'])
            ->where('status', ApplicationStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (PersonalInformationChangeRequest $request): array => $this->item('生徒情報変更', $request->studentProfile->user->name, '生徒情報の変更申請があります。', $request->requested_at, route('staff.personal-information-change-requests.show', ['personal_change' => $request]), 'request'));

        $this->append($items, PaymentMethodChangeRequest::query()
            ->with(['studentProfile.user'])
            ->where('status', ApplicationStatus::Pending)
            ->orderByDesc('requested_at')->get(),
            fn (PaymentMethodChangeRequest $request): array => $this->item('支払方法変更', $request->studentProfile->user->name, '支払方法の変更申請があります。', $request->requested_at, route('staff.payment-method-change-requests.show', ['payment_change' => $request]), 'request'));

        $this->append($items, Inquiry::query()
            ->with(['studentProfile.user'])
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByDesc('requested_at')->get(),
            fn (Inquiry $inquiry): array => $this->item('お問い合わせ', $inquiry->studentProfile?->user?->name ?? '生徒', $inquiry->subject, $inquiry->requested_at, route('staff.inquiries.show', $inquiry), 'request'));

        return $items->sortByDesc(fn (array $item): int => $item['sort_at']->getTimestamp())->values();
    }

    public function upcomingPauseDeadlines(): Collection
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $windowEnd = $today->addDays(30);
        $pausedEnrollments = LessonEnrollment::query()
            ->with(['studentProfile.user'])
            ->where('status', EnrollmentStatus::Paused)
            ->get()
            ->unique('student_profile_id')
            ->values();

        return $pausedEnrollments->map(function (LessonEnrollment $enrollment) use ($today, $windowEnd): ?array {
            $pause = MembershipStatusRequest::query()
                ->where('student_profile_id', $enrollment->student_profile_id)
                ->where('type', MembershipRequestType::Pause)
                ->where('status', ApplicationStatus::Approved)
                ->whereNotNull('applied_at')
                ->whereNotNull('pause_months')
                ->latest('effective_on')
                ->first();

            if ($pause === null) {
                return null;
            }

            $deadline = CarbonImmutable::instance($pause->effective_on)
                ->startOfDay()
                ->addMonthsNoOverflow($pause->pause_months);

            if ($deadline->greaterThan($windowEnd)) {
                return null;
            }

            return $this->item(
                $deadline->lessThan($today) ? '休会期限超過' : '休会期限が近づいています',
                $enrollment->studentProfile->user->name,
                '休会予定の終了日：'.$deadline->format('Y年n月j日').'。再開予定を確認してください。',
                $deadline,
                route('staff.students.show', $enrollment->studentProfile),
                'deadline',
                true,
            );
        })->filter()->sortBy(fn (array $item): int => $item['sort_at']->getTimestamp())->values();
    }

    /** @return array{pending_requests: int, upcoming_pause_deadlines: int} */
    public function summary(): array
    {
        return [
            'pending_requests' => $this->items()->where('category', 'request')->count(),
            'upcoming_pause_deadlines' => $this->upcomingPauseDeadlines()->count(),
        ];
    }

    private function append(Collection $items, Collection $records, callable $toItem): void
    {
        $records->each(fn ($record) => $items->push($toItem($record)));
    }

    /** @return array{title: string, student: string, message: string, date: CarbonImmutable, sort_at: CarbonImmutable, url: string, category: string, urgent: bool} */
    private function item(string $title, string $student, string $message, mixed $date, string $url, string $category, bool $urgent = false): array
    {
        $sortAt = $date instanceof CarbonImmutable
            ? $date
            : CarbonImmutable::parse($date ?? now(), config('app.timezone'));

        return [
            'title' => $title,
            'student' => $student,
            'message' => $message,
            'date' => $sortAt,
            'sort_at' => $sortAt,
            'url' => $url,
            'category' => $category,
            'urgent' => $urgent,
        ];
    }
}
