<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceItemType;
use App\Enums\MembershipRequestType;
use App\Enums\MonthlyInvoiceStatus;
use App\Enums\ReservationStatus;
use App\Models\BillingSetting;
use App\Models\LessonEnrollment;
use App\Models\MonthlyInvoice;
use App\Models\MonthlyInvoiceAudit;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonthlyInvoiceGenerator
{
    public function __construct(
        private readonly LessonPricingService $pricing,
        private readonly MonthlyInvoiceTotals $totals,
    ) {}

    /** @return Collection<int, MonthlyInvoice> */
    public function generate(mixed $month, ?User $actor = null): Collection
    {
        $billingMonth = CarbonImmutable::parse($month, config('app.timezone'))->startOfMonth();
        $end = $billingMonth->endOfMonth();

        return StudentProfile::query()
            ->whereHas('enrollments', fn ($query) => $query
                ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Ended])
                ->whereDate('starts_on', '<=', $end)
                ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $billingMonth)))
            ->orderBy('id')
            ->get()
            ->reject(fn (StudentProfile $student) => $this->inactiveForWholeMonth($student, $billingMonth, $end))
            ->map(fn (StudentProfile $student) => $this->generateForStudent($student, $billingMonth, $actor))
            ->filter()
            ->values();
    }

    public function generateForStudent(StudentProfile $student, CarbonImmutable $month, ?User $actor = null): ?MonthlyInvoice
    {
        $month = $month->startOfMonth();

        return DB::transaction(function () use ($student, $month, $actor): ?MonthlyInvoice {
            StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $invoice = MonthlyInvoice::query()
                ->whereBelongsTo($student)
                ->whereDate('billing_month', $month)
                ->where('status', '!=', MonthlyInvoiceStatus::Cancelled)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                $cancelled = MonthlyInvoice::query()->whereBelongsTo($student)
                    ->whereDate('billing_month', $month)->where('status', MonthlyInvoiceStatus::Cancelled)
                    ->latest('id')->first();
                if ($cancelled !== null) {
                    return $cancelled;
                }
            }

            if ($invoice !== null && ($invoice->status !== MonthlyInvoiceStatus::Draft || $invoice->has_manual_adjustments)) {
                return $invoice;
            }

            $enrollments = LessonEnrollment::query()
                ->whereBelongsTo($student)
                ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Ended])
                ->whereDate('starts_on', '<=', $month->endOfMonth())
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $month))
                ->with(['course', 'teacherProfile', 'venue'])
                ->orderBy('starts_on')
                ->orderBy('id')
                ->get();

            if ($enrollments->isEmpty()) {
                return null;
            }

            $groups = $enrollments->groupBy(fn (LessonEnrollment $enrollment) => $this->chainRootId($enrollment));
            $selected = $groups->map(fn (Collection $versions) => $versions
                ->first(fn (LessonEnrollment $item) => $item->starts_on->lessThanOrEqualTo($month)
                    && ($item->ends_on === null || $item->ends_on->greaterThanOrEqualTo($month)))
                ?? $versions->first());
            $warnings = [];
            if ($student->joined_on?->greaterThan($month)) {
                $warnings[] = '月途中入会のため管理者確認が必要です。日割り計算はしていません。';
            }
            if ($groups->contains(fn (Collection $versions) => $versions->count() > 1
                || $versions->contains(fn (LessonEnrollment $item) => $item->starts_on->between($month->addDay(), $month->endOfMonth())))) {
                $warnings[] = '月途中契約変更があります。自動按分せず、月初時点または開始時点の契約を使用しています。';
            }
            if ($student->membershipStatusRequests()->where('status', ApplicationStatus::Approved)
                ->whereBetween('effective_on', [$month, $month->endOfMonth()])->exists()) {
                $warnings[] = '月途中の在籍状態変更があります。請求内容を確認してください。';
            }

            $setting = BillingSetting::current();
            if ($invoice === null) {
                $candidate = MonthlyInvoice::query()->firstOrCreate([
                    'student_profile_id' => $student->id,
                    'billing_month' => $month->toDateString(),
                ], [
                    'status' => MonthlyInvoiceStatus::Draft,
                    'generated_at' => now(),
                    'generated_by_user_id' => $actor?->id,
                ]);
                $invoice = MonthlyInvoice::query()->lockForUpdate()->findOrFail($candidate->id);
                if (! $candidate->wasRecentlyCreated
                    && ($invoice->status !== MonthlyInvoiceStatus::Draft || $invoice->has_manual_adjustments)) {
                    return $invoice;
                }
            }
            $invoice->items()->where('is_manual', false)->delete();
            $contractSnapshots = [];
            $pricingSnapshots = [];
            $paymentMethod = null;

            foreach ($selected as $enrollment) {
                $quote = $this->pricing->forEnrollment($enrollment, $month);
                $snapshot = [
                    'lesson_enrollment_id' => $enrollment->id,
                    'course_id' => $enrollment->course_id,
                    'course_name' => $enrollment->course?->name,
                    'lesson_type' => $enrollment->lesson_type->value,
                    'pricing_category' => $enrollment->pricing_category->value,
                    'monthly_lesson_count' => (int) $enrollment->monthly_lesson_limit,
                    'lesson_minutes' => (int) $enrollment->lesson_minutes,
                    'starts_on' => $enrollment->starts_on->toDateString(),
                    'ends_on' => $enrollment->ends_on?->toDateString(),
                ];
                $priceSnapshot = [
                    ...$snapshot,
                    'base_lesson_fee' => $quote->baseLessonFee,
                    'flex_surcharge' => $quote->flexSurcharge,
                    'lesson_fee_total' => $quote->lessonFeeTotal,
                    'priced_on' => $month->toDateString(),
                ];
                $contractSnapshots[] = $snapshot;
                $pricingSnapshots[] = $priceSnapshot;
                $paymentMethod ??= $enrollment->payment_method;

                if ($quote->isConsultationRequired) {
                    $warnings[] = ($enrollment->course?->name ?? '契約').'の料金表が存在しないため要相談です。';
                } else {
                    $invoice->items()->create([
                        'type' => InvoiceItemType::LessonFee,
                        'description' => ($enrollment->course?->name ?? 'レッスン').' 月額レッスン料金',
                        'unit_amount' => $quote->baseLessonFee,
                        'amount' => $quote->baseLessonFee,
                        'source_type' => LessonEnrollment::class,
                        'source_id' => $enrollment->id,
                        'pricing_snapshot' => $priceSnapshot,
                    ]);
                    if ($quote->flexSurcharge > 0) {
                        $invoice->items()->create([
                            'type' => InvoiceItemType::FlexSurcharge,
                            'description' => 'フレックス加算',
                            'unit_amount' => $quote->flexSurcharge,
                            'amount' => $quote->flexSurcharge,
                            'source_type' => LessonEnrollment::class,
                            'source_id' => $enrollment->id,
                            'pricing_snapshot' => $priceSnapshot,
                        ]);
                    }
                }
            }

            $reservations = $student->reservationRequests()
                ->where('status', ReservationStatus::Approved)
                ->whereDate('lesson_entitlement_month', $month)
                ->whereNotNull('studio_fee_amount')
                ->with('lessonSlot')
                ->get();
            foreach ($reservations as $reservation) {
                $invoice->items()->create([
                    'type' => InvoiceItemType::StudioFee,
                    'description' => 'スタジオ使用料 '.($reservation->lessonSlot?->starts_at?->format('n/j H:i') ?? ''),
                    'unit_amount' => (int) $reservation->studio_fee_amount,
                    'amount' => (int) $reservation->studio_fee_amount,
                    'source_type' => $reservation::class,
                    'source_id' => $reservation->id,
                    'pricing_snapshot' => ['studio_fee_amount' => (int) $reservation->studio_fee_amount, 'studio_fee_priced_on' => $reservation->studio_fee_priced_on?->toDateString()],
                ]);
            }

            $missingStudioFeeCount = $student->reservationRequests()
                ->where('status', ReservationStatus::Approved)
                ->whereDate('lesson_entitlement_month', $month)
                ->whereNull('studio_fee_amount')
                ->count();
            if ($missingStudioFeeCount > 0) {
                $warnings[] = "スタジオ代の保存がない承認済み予約が{$missingStudioFeeCount}件あります。請求前に予約を確認してください。";
            }

            $missingMonthCount = $student->reservationRequests()
                ->where('status', ReservationStatus::Approved)
                ->whereNull('lesson_entitlement_month')
                ->whereHas('lessonSlot', fn ($query) => $query->whereBetween('starts_at', [$month, $month->endOfMonth()]))
                ->count();
            if ($missingMonthCount > 0) {
                $warnings[] = "対象月が未設定の旧予約が{$missingMonthCount}件あります。料金記録と請求対象月を管理者が確認してください。";
            }

            $invoice->update([
                'payment_method' => $paymentMethod,
                'due_on' => $setting->dueDateFor($month)->toDateString(),
                'contract_snapshot' => $contractSnapshots,
                'pricing_snapshot' => $pricingSnapshots,
                'warnings' => array_values(array_unique($warnings)),
                'requires_review' => $warnings !== [],
                'generated_at' => now(),
                'generated_by_user_id' => $actor?->id,
            ]);
            $invoice = $this->totals->recalculate($invoice);
            MonthlyInvoiceAudit::query()->create([
                'monthly_invoice_id' => $invoice->id,
                'actor_user_id' => $actor?->id,
                'event' => 'draft_generated',
                'after_values' => ['total_amount' => $invoice->total_amount, 'warnings' => $invoice->warnings],
                'created_at' => now(),
            ]);

            return $invoice;
        }, 3);
    }

    private function chainRootId(LessonEnrollment $enrollment): int
    {
        $current = $enrollment;
        $seen = [];
        while ($current->supersedes_lesson_enrollment_id !== null && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $parent = LessonEnrollment::query()->find($current->supersedes_lesson_enrollment_id);
            if ($parent === null) {
                break;
            }
            $current = $parent;
        }

        return $current->id;
    }

    private function inactiveForWholeMonth(StudentProfile $student, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $lastBeforeOrDuring = $student->membershipStatusRequests()
            ->where('status', ApplicationStatus::Approved)
            ->whereDate('effective_on', '<=', $start)
            ->latest('effective_on')
            ->latest('id')
            ->first();
        if ($lastBeforeOrDuring === null || $lastBeforeOrDuring->type === MembershipRequestType::Resume) {
            return false;
        }

        return ! $student->membershipStatusRequests()
            ->where('status', ApplicationStatus::Approved)
            ->where('type', MembershipRequestType::Resume)
            ->whereBetween('effective_on', [$start, $end])
            ->exists();
    }
}
