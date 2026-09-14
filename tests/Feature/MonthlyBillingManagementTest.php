<?php

namespace Tests\Feature;

use App\Actions\AddInvoiceAdjustment;
use App\Actions\CancelMonthlyInvoice;
use App\Actions\ConfirmMonthlyInvoice;
use App\Actions\RegisterInvoicePayment;
use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\LessonType;
use App\Enums\MembershipRequestType;
use App\Enums\MonthlyInvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Enums\ReservationStatus;
use App\Models\BillingSetting;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\MonthlyInvoice;
use App\Models\PriceRate;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\MonthlyInvoiceConfirmedNotification;
use App\Notifications\MonthlyInvoicePaidNotification;
use App\Services\MonthlyInvoiceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonthlyBillingManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('priceCases')]
    public function test_generates_lesson_fee_snapshots_for_each_contract_type(string $lessonType, string $category, int $expected): void
    {
        $this->seed();
        [$student] = $this->studentWithEnrollment(['lesson_type' => $lessonType, 'pricing_category' => $category]);

        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10')->sole();

        $this->assertSame($student->id, $invoice->student_profile_id);
        $this->assertSame($expected, $invoice->lesson_fee_total);
        $this->assertSame($lessonType, $invoice->pricing_snapshot[0]['lesson_type']);
        $this->assertSame($category, $invoice->pricing_snapshot[0]['pricing_category']);
        $this->assertSame(2, $invoice->pricing_snapshot[0]['monthly_lesson_count']);
    }

    public function test_confirmed_snapshot_does_not_change_after_price_history_changes(): void
    {
        Notification::fake();
        $this->seed();
        $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);
        PriceRate::factory()->create([
            'kind' => PriceRateKind::RegularLesson,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_count' => 2,
            'amount' => 99999,
            'effective_from' => '2026-10-01',
        ]);

        app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin);

        $this->assertSame(11000, $invoice->fresh()->lesson_fee_total);
        $this->assertSame(11000, $invoice->fresh()->pricing_snapshot[0]['base_lesson_fee']);
    }

    public function test_uses_reservation_studio_snapshots_and_excludes_cancelled_or_transferred_originals(): void
    {
        $this->seed();
        [$student, $enrollment] = $this->studentWithEnrollment();
        $included = $this->reservation($student, $enrollment, '2026-10-08 18:00', ReservationStatus::Approved, 1610);
        $this->reservation($student, $enrollment, '2026-10-15 18:00', ReservationStatus::Cancelled, 1610);
        $this->reservation($student, $enrollment, '2026-11-05 18:00', ReservationStatus::Approved, 1610, '2026-10-01');
        $transferredOriginal = $this->reservation($student, $enrollment, '2026-10-22 18:00', ReservationStatus::Cancelled, null);

        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10')->sole();

        $this->assertSame(3220, $invoice->studio_fee_total);
        $this->assertCount(2, $invoice->items->where('type', 'studio_fee'));
        $this->assertFalse($invoice->items->contains('source_id', $transferredOriginal->id));
        $this->assertTrue($invoice->items->contains('source_id', $included->id));
    }

    public function test_absence_keeps_monthly_fee_and_studio_snapshot(): void
    {
        $this->seed();
        [$student, $enrollment] = $this->studentWithEnrollment();
        $reservation = $this->reservation($student, $enrollment, '2026-10-08 18:00', ReservationStatus::Approved, 1610);
        $reservation->attendanceNotice()->create(['type' => 'absence', 'submitted_at' => now()]);

        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10')->sole();

        $this->assertSame(11000, $invoice->lesson_fee_total);
        $this->assertSame(1610, $invoice->studio_fee_total);
    }

    public function test_monthly_join_and_mid_month_contract_change_are_warning_only_without_proration(): void
    {
        $this->seed();
        [$student, $first] = $this->studentWithEnrollment(['starts_on' => '2026-10-10']);
        $student->update(['joined_on' => '2026-10-10']);
        LessonEnrollment::factory()->for($student)->create([
            'course_id' => $first->course_id,
            'lesson_type' => LessonType::Regular,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_limit' => 4,
            'starts_on' => '2026-10-20',
            'supersedes_lesson_enrollment_id' => $first->id,
            'status' => EnrollmentStatus::Active,
        ]);

        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10')->sole();

        $this->assertTrue($invoice->requires_review);
        $this->assertStringContainsString('月途中入会', implode(' ', $invoice->warnings));
        $this->assertStringContainsString('月途中契約変更', implode(' ', $invoice->warnings));
        $this->assertSame(11000, $invoice->lesson_fee_total);
    }

    public function test_missing_rate_is_not_silently_billed_as_zero_and_requires_acknowledgement(): void
    {
        Notification::fake();
        $this->seed();
        $this->studentWithEnrollment(['monthly_lesson_limit' => 5]);
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();

        $this->assertTrue($invoice->requires_review);
        $this->assertNull($invoice->lesson_fee_total);
        try {
            app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);
            $this->fail('警告未確認の確定が拒否されませんでした。');
        } catch (ValidationException) {
            $this->assertSame(MonthlyInvoiceStatus::Draft, $invoice->fresh()->status);
        }
        app(AddInvoiceAdjustment::class)->handle($invoice, $admin, 25000, '管理者確認済み料金', '要相談契約料金');
        app(ConfirmMonthlyInvoice::class)->handle($invoice->fresh(), $admin, true);
        $this->assertSame(25000, $invoice->fresh()->total_amount);
    }

    public function test_fully_paused_or_withdrawn_student_is_skipped(): void
    {
        $this->seed();
        [$paused] = $this->studentWithEnrollment();
        [$active] = $this->studentWithEnrollment();
        foreach ([$paused, $active] as $student) {
            MembershipStatusRequest::factory()->for($student)->create([
                'type' => MembershipRequestType::Pause,
                'status' => ApplicationStatus::Approved,
                'effective_on' => '2026-09-20',
            ]);
        }
        MembershipStatusRequest::factory()->for($active)->create([
            'type' => MembershipRequestType::Resume,
            'status' => ApplicationStatus::Approved,
            'effective_on' => '2026-10-10',
        ]);

        $invoices = app(MonthlyInvoiceGenerator::class)->generate('2026-10');

        $this->assertFalse($invoices->contains('student_profile_id', $paused->id));
        $this->assertTrue($invoices->contains('student_profile_id', $active->id));
    }

    public function test_regeneration_is_idempotent_and_preserves_manual_adjustments(): void
    {
        $this->seed();
        $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $generator = app(MonthlyInvoiceGenerator::class);
        $invoice = $generator->generate('2026-10', $admin)->sole();
        app(AddInvoiceAdjustment::class)->handle($invoice, $admin, -1000, '紹介割引', '割引');

        $again = $generator->generate('2026-10', $admin)->sole();

        $this->assertSame($invoice->id, $again->id);
        $this->assertSame(10000, $again->total_amount);
        $this->assertDatabaseCount('monthly_invoices', 1);
        $this->assertDatabaseHas('invoice_items', ['monthly_invoice_id' => $invoice->id, 'is_manual' => true, 'amount' => -1000]);
    }

    public function test_confirmation_assigns_unique_human_number_locks_amount_and_sends_once(): void
    {
        Notification::fake();
        $this->seed();
        [$student] = $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();

        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);

        $invoice->refresh();
        $this->assertMatchesRegularExpression('/^INV-202610-\d{6}$/', $invoice->invoice_number);
        Notification::assertSentTo($student->user, MonthlyInvoiceConfirmedNotification::class);
        $this->expectException(ValidationException::class);
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);
    }

    public function test_confirmed_invoice_cannot_be_adjusted_and_cancellation_keeps_history(): void
    {
        Notification::fake();
        $this->seed();
        $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);
        try {
            app(AddInvoiceAdjustment::class)->handle($invoice->fresh(), $admin, 500, '確定後変更');
            $this->fail('確定後の調整が拒否されませんでした。');
        } catch (ValidationException) {
            $this->assertSame(11000, $invoice->fresh()->total_amount);
        }

        app(CancelMonthlyInvoice::class)->handle($invoice->fresh(), $admin, '請求対象月の訂正');

        $this->assertSame(MonthlyInvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(11000, $invoice->fresh()->total_amount);
        $this->assertDatabaseHas('monthly_invoice_audits', ['monthly_invoice_id' => $invoice->id, 'event' => 'cancelled']);
    }

    public function test_partial_and_full_payments_recalculate_balance_and_notify_only_on_completion(): void
    {
        Notification::fake();
        $this->seed();
        [$student] = $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);
        Notification::fake();
        $action = app(RegisterInvoicePayment::class);

        $action->handle($invoice, $admin, $this->paymentData(5000, 'part-1'));
        $this->assertSame(InvoicePaymentStatus::PartiallyPaid, $invoice->fresh()->payment_status);
        $this->assertSame(6000, $invoice->fresh()->remaining_amount);
        Notification::assertNothingSent();
        $action->handle($invoice->fresh(), $admin, $this->paymentData(6000, 'part-2'));
        $this->assertSame(InvoicePaymentStatus::Paid, $invoice->fresh()->payment_status);
        $this->assertSame(0, $invoice->fresh()->remaining_amount);
        Notification::assertSentToTimes($student->user, MonthlyInvoicePaidNotification::class, 1);
        $this->assertDatabaseCount('payment_records', 2);
    }

    public function test_legacy_reservation_without_fee_or_month_requires_invoice_review(): void
    {
        $this->seed();
        [$student, $enrollment] = $this->studentWithEnrollment();
        $slot = LessonSlot::factory()->create(['starts_at' => '2026-10-10 14:00:00', 'ends_at' => '2026-10-10 15:00:00']);
        ReservationRequest::factory()->for($student)->for($enrollment)->for($slot)->create([
            'status' => ReservationStatus::Approved,
            'lesson_entitlement_month' => null,
            'studio_fee_amount' => null,
        ]);
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();

        $this->assertTrue($invoice->requires_review);
        $this->assertStringContainsString('対象月が未設定の旧予約', implode(' ', $invoice->warnings));
        $this->actingAs($admin)->post(route('staff.invoices.confirm', $invoice))->assertSessionHasErrors();
        $this->assertSame(MonthlyInvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_payment_form_repeated_submission_records_cash_once(): void
    {
        $this->seed();
        $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);
        $page = $this->actingAs($admin)->get(route('staff.invoices.show', $invoice))->assertOk();
        preg_match('/name="idempotency_key" value="([^"]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $data = [...$this->paymentData(5000, ''), 'idempotency_key' => $matches[1]];
        $withoutKey = $data;
        unset($withoutKey['idempotency_key']);
        $this->post(route('staff.invoices.payments.store', $invoice), $withoutKey)->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, $invoice->paymentRecords()->count());
        $this->post(route('staff.invoices.payments.store', $invoice), $data)->assertSessionHasNoErrors();
        $this->post(route('staff.invoices.payments.store', $invoice), $data)->assertSessionHasNoErrors();
        $this->assertSame(5000, $invoice->fresh()->paid_amount);
        $this->assertSame(1, $invoice->paymentRecords()->count());
    }

    public function test_overpayment_is_rejected_inside_locked_payment_flow(): void
    {
        Notification::fake();
        $this->seed();
        $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);

        $this->expectException(ValidationException::class);
        app(RegisterInvoicePayment::class)->handle($invoice, $admin, $this->paymentData(11001, 'too-much'));
    }

    public function test_due_rule_and_overdue_state_are_configurable_without_late_fee(): void
    {
        Notification::fake();
        $this->seed();
        $this->studentWithEnrollment();
        BillingSetting::current()->update(['due_rule' => 'billing_month_day', 'due_day' => 10]);
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));

        $this->assertSame('2026-10-10', $invoice->fresh()->due_on->toDateString());
        $this->assertTrue($invoice->fresh()->is_overdue);
        $this->assertSame(11000, $invoice->fresh()->total_amount);
    }

    public function test_student_sees_only_own_confirmed_invoices_and_never_drafts(): void
    {
        Notification::fake();
        $this->seed();
        [$student] = $this->studentWithEnrollment();
        [$other] = $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoices = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin);
        $own = $invoices->firstWhere('student_profile_id', $student->id);
        $otherInvoice = $invoices->firstWhere('student_profile_id', $other->id);
        app(ConfirmMonthlyInvoice::class)->handle($own, $admin);

        $this->actingAs($student->user)->get(route('student.invoices.index'))
            ->assertOk()->assertSee($own->fresh()->invoice_number)->assertDontSee($other->user->name);
        $this->actingAs($student->user)->get(route('student.invoices.show', $otherInvoice))->assertForbidden();
        $this->actingAs($other->user)->get(route('student.invoices.show', $otherInvoice))->assertForbidden();
    }

    public function test_teacher_is_read_only_and_admin_can_generate_adjust_and_receive_payment(): void
    {
        Notification::fake();
        $this->seed();
        $this->studentWithEnrollment();
        $teacher = User::factory()->teacher()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($teacher)->get(route('staff.invoices.index', ['month' => '2026-10']))->assertOk();
        $this->actingAs($teacher)->post(route('staff.invoices.generate'), ['month' => '2026-10'])->assertForbidden();
        $this->actingAs($admin)->post(route('staff.invoices.generate'), ['month' => '2026-10'])->assertRedirect();
        $invoice = MonthlyInvoice::query()->firstOrFail();
        $this->actingAs($admin)->post(route('staff.invoices.adjustments.store', $invoice), ['description' => '教材', 'amount' => 500, 'reason' => 'スティック代'])->assertRedirect();
        $this->actingAs($admin)->post(route('staff.invoices.confirm', $invoice))->assertRedirect();
        $this->actingAs($admin)->post(route('staff.invoices.payments.store', $invoice), $this->paymentData(11500, 'admin-payment'))->assertRedirect();
        $this->assertSame(InvoicePaymentStatus::Paid, $invoice->fresh()->payment_status);
    }

    public function test_scheduler_force_generates_next_month_draft_and_never_confirms_it(): void
    {
        $this->seed();
        $this->studentWithEnrollment(['starts_on' => '2026-09-01']);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 02:00', 'Asia/Tokyo'));

        $this->artisan('billing:generate-next-month --force')->assertSuccessful();

        $invoice = MonthlyInvoice::query()->sole();
        $this->assertSame('2026-10-01', $invoice->billing_month->toDateString());
        $this->assertSame(MonthlyInvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->invoice_number);
    }

    public function test_sensitive_payment_credentials_have_no_database_columns(): void
    {
        foreach (['card_number', 'card_expiry', 'security_code', 'bank_account_number', 'pin', 'secret_key'] as $column) {
            $this->assertFalse(Schema::hasColumn('monthly_invoices', $column));
            $this->assertFalse(Schema::hasColumn('payment_records', $column));
        }
    }

    public function test_payment_notes_reject_card_or_account_like_numbers(): void
    {
        Notification::fake();
        $this->seed();
        $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin);

        $this->actingAs($admin)->post(route('staff.invoices.payments.store', $invoice), [
            ...$this->paymentData(1000, 'safe-reference'),
            'notes' => '口座 1234-5678-9012',
        ])->assertSessionHasErrors('notes');
        $this->assertDatabaseCount('payment_records', 0);
    }

    public static function priceCases(): array
    {
        return [
            'regular standard' => ['regular', 'standard', 11000],
            'regular junior' => ['regular', 'junior', 10000],
            'flex standard' => ['flex', 'standard', 11500],
            'flex junior' => ['flex', 'junior', 10500],
        ];
    }

    private function studentWithEnrollment(array $overrides = []): array
    {
        $student = StudentProfile::factory()->create(['joined_on' => '2026-01-01']);
        $course = Course::query()->first() ?? Course::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create([
            'course_id' => $course->id,
            'lesson_type' => LessonType::Regular,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_limit' => 2,
            'payment_method' => PaymentMethod::BankTransfer,
            'starts_on' => '2026-01-01',
            ...$overrides,
        ]);

        return [$student, $enrollment];
    }

    private function reservation(StudentProfile $student, LessonEnrollment $enrollment, string $startsAt, ReservationStatus $status, ?int $studioFee, string $entitlementMonth = '2026-10-01'): ReservationRequest
    {
        $slot = LessonSlot::factory()->create(['course_id' => $enrollment->course_id, 'starts_at' => $startsAt, 'ends_at' => CarbonImmutable::parse($startsAt)->addHour()]);

        return ReservationRequest::factory()->for($student)->for($slot, 'lessonSlot')->create([
            'lesson_enrollment_id' => $enrollment->id,
            'status' => $status,
            'lesson_entitlement_month' => $entitlementMonth,
            'studio_fee_amount' => $studioFee,
            'studio_fee_priced_on' => $studioFee === null ? null : '2026-09-01',
        ]);
    }

    private function paymentData(int $amount, string $reference): array
    {
        return [
            'idempotency_key' => Str::uuid()->toString(),
            'amount' => $amount,
            'paid_on' => '2026-09-30',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'external_payment_provider' => 'test-provider',
            'external_payment_reference' => $reference,
            'notes' => 'テスト入金',
        ];
    }
}
