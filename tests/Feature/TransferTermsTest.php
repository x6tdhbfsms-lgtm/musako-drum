<?php

namespace Tests\Feature;

use App\Actions\AddInvoiceAdjustment;
use App\Actions\CancelTrialLessonRequest;
use App\Actions\ConfirmMonthlyInvoice;
use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Enums\PriceRateKind;
use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\PriceRate;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use App\Models\TrialLessonRequest;
use App\Models\User;
use App\Models\Venue;
use App\Services\LessonPricingService;
use App\Services\MonthlyInvoiceGenerator;
use App\Services\PausedScheduleRelease;
use App\Services\TransferTermsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferTermsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function cancellationTimes(): array
    {
        return [
            'one week' => ['2026-09-13 23:59:59', 0],
            'six days' => ['2026-09-14 00:00:00', 805],
            'two days' => ['2026-09-18 23:59:59', 805],
            'previous day' => ['2026-09-19 00:00:00', 1610],
            'same day' => ['2026-09-20 10:00:00', 1610],
        ];
    }

    #[DataProvider('cancellationTimes')]
    public function test_studio_cancellation_uses_calendar_days(string $date, int $amount): void
    {
        $transfer = $this->transfer();
        $this->assertSame($amount, app(TransferTermsService::class)->studioCancellationFee(
            $transfer->originalReservationRequest->lessonSlot, CarbonImmutable::parse($date),
        ));
    }

    public function test_october_enrollment_has_three_free_transfers_until_march(): void
    {
        $this->travelTo('2026-10-01');
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['starts_on' => '2026-10-01', 'monthly_lesson_limit' => 2]);

        $allowance = app(TransferTermsService::class)->annualAllowance($student, CarbonImmutable::parse('2026-10-31'));

        $this->assertSame(12, $allowance['planned_lessons']);
        $this->assertSame(3, $allowance['free_transfer_limit']);
        $this->assertSame('2026-04-01', $allowance['year_start']->toDateString());
        $this->assertSame('2027-03-31', $allowance['year_end']->toDateString());
    }

    public function test_contract_versions_are_not_double_counted_and_april_resets_the_year(): void
    {
        $this->travelTo('2026-10-01');
        $student = StudentProfile::factory()->create();
        $first = LessonEnrollment::factory()->for($student)->create([
            'starts_on' => '2026-04-01', 'ends_on' => '2026-09-30', 'monthly_lesson_limit' => 2,
        ]);
        LessonEnrollment::factory()->for($student)->create([
            'starts_on' => '2026-10-01', 'monthly_lesson_limit' => 4, 'supersedes_lesson_enrollment_id' => $first->id,
        ]);
        $terms = app(TransferTermsService::class);

        $this->assertSame(9, $terms->annualAllowance($student, CarbonImmutable::parse('2027-03-31'))['free_transfer_limit']);
        $this->assertSame(12, $terms->annualAllowance($student, CarbonImmutable::parse('2027-04-01'))['free_transfer_limit']);
    }

    public function test_previous_day_electronic_course_has_no_extra_fee(): void
    {
        $transfer = $this->transfer();
        $transfer->originalReservationRequest->lessonSlot->venue->update(['lesson_room_type' => 'music_room']);
        $quote = app(TransferTermsService::class)->quote($transfer->fresh(), CarbonImmutable::parse('2026-09-19 11:00'));

        $this->assertSame(0, $quote['total']);
    }

    public function test_cancellation_and_late_transfer_fees_are_separate_components(): void
    {
        $transfer = $this->transfer();
        $quote = app(TransferTermsService::class)->quote($transfer, CarbonImmutable::parse('2026-09-17 10:00'));

        $this->assertSame(['studio_cancellation' => 805, 'late_transfer' => 1000, 'excess_lesson' => 0, 'excess_surcharge' => 0], $quote['components']);
        $this->assertSame(1805, $quote['total']);
    }

    public function test_seventh_transfer_adds_one_lesson_and_two_thousand_separately(): void
    {
        $transfer = $this->transfer();
        TransferRequest::factory()->count(6)->create([
            'student_profile_id' => $transfer->student_profile_id, 'status' => ApplicationStatus::Approved,
            'reviewed_at' => '2026-06-01',
        ]);
        $quote = app(TransferTermsService::class)->quote($transfer, CarbonImmutable::parse('2026-09-17 10:00'));

        $this->assertSame(6, $quote['used_before']);
        $this->assertSame(4000, $quote['components']['excess_lesson']);
        $this->assertSame(2000, $quote['components']['excess_surcharge']);
        $this->assertSame(7805, $quote['total']);
    }

    public function test_teacher_transfers_do_not_count_toward_the_allowance(): void
    {
        $transfer = $this->transfer();
        TransferRequest::factory()->create([
            'student_profile_id' => $transfer->student_profile_id, 'status' => ApplicationStatus::Approved,
            'reviewed_at' => '2026-06-01', 'terms_snapshot' => ['cause' => 'teacher'],
        ]);

        $quote = app(TransferTermsService::class)->quote($transfer, CarbonImmutable::parse('2026-09-19 11:00'), 'teacher');

        $this->assertSame(0, $quote['used_before']);
        $this->assertSame(0, $quote['total']);
    }

    public function test_early_student_contact_does_not_bypass_the_formal_deadline(): void
    {
        $transfer = $this->transfer();
        $this->travelTo('2026-09-19 12:00:01');

        $this->actingAs($transfer->originalReservationRequest->lessonSlot->teacherProfile->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])
            ->assertSessionHasErrors(['transfer_request' => '正式な申請期限（前日12:00）を過ぎています。講師の確定日時で判定します。']);

        $this->assertSame(ApplicationStatus::Pending, $transfer->fresh()->status);
        $this->assertSame(ReservationStatus::Approved, $transfer->originalReservationRequest->fresh()->status);
    }

    public function test_emergency_exception_requires_a_reason_and_charges_only_the_venue(): void
    {
        $transfer = $this->transfer();
        $this->travelTo('2026-09-20 10:00:00');

        $this->actingAs($transfer->originalReservationRequest->lessonSlot->teacherProfile->user)
            ->patch(route('staff.transfer-requests.update', $transfer), [
                'decision' => 'approved', 'cause' => 'emergency', 'staff_note' => '公共交通機関の人身事故',
            ])->assertSessionHasNoErrors();

        $snapshot = $transfer->fresh()->terms_snapshot;
        $this->assertSame(1610, $snapshot['total']);
        $this->assertSame('emergency', $snapshot['cause']);
        $this->assertSame('公共交通機関の人身事故', $snapshot['exception_reason']);
    }

    public function test_teacher_exception_without_a_reason_is_rejected(): void
    {
        $transfer = $this->transfer();

        $this->actingAs($transfer->originalReservationRequest->lessonSlot->teacherProfile->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved', 'cause' => 'teacher'])
            ->assertSessionHasErrors('staff_note');

        $this->assertSame(ApplicationStatus::Pending, $transfer->fresh()->status);
    }

    public function test_transfer_cannot_be_transferred_again_even_by_posting_directly(): void
    {
        $transfer = $this->transfer();
        $original = $transfer->originalReservationRequest;
        TransferRequest::factory()->create(['resulting_reservation_request_id' => $original->id, 'status' => ApplicationStatus::Approved]);

        $this->actingAs($original->studentProfile->user)->post(route('student.transfer-requests.store', $original), [
            'requested_lesson_slot_id' => $transfer->requested_lesson_slot_id,
        ])->assertSessionHasErrors(['reservation' => '振替レッスンを再度振替することはできません。']);
    }

    public function test_approval_rechecks_a_pause_approved_after_the_transfer_request(): void
    {
        $transfer = $this->transfer();
        MembershipStatusRequest::factory()->create([
            'student_profile_id' => $transfer->student_profile_id, 'type' => MembershipRequestType::Pause,
            'effective_on' => '2026-09-01', 'status' => ApplicationStatus::Approved,
        ]);

        $this->actingAs($transfer->originalReservationRequest->lessonSlot->teacherProfile->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])
            ->assertSessionHasErrors(['transfer_request' => '休会・退会月以降へ振替レッスンを繰り越すことはできません。']);
    }

    public function test_fee_snapshot_is_billed_once_and_confirmed_invoices_are_unchanged(): void
    {
        $transfer = $this->transfer();
        $this->actingAs($transfer->originalReservationRequest->lessonSlot->teacherProfile->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $generator = app(MonthlyInvoiceGenerator::class);
        $student = $transfer->studentProfile;
        $month = CarbonImmutable::parse('2026-09-01');
        $invoice = $generator->generateForStudent($student, $month);
        $generator->generateForStudent($student, $month);

        $this->assertSame(2, $invoice->items()->where('source_type', TransferRequest::class)->count());
        $this->assertSame(1805, (int) $invoice->items()->where('source_type', TransferRequest::class)->sum('amount'));
        $admin = User::factory()->admin()->create();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin, true);
        $total = $invoice->fresh()->total_amount;
        $generator->generateForStudent($student, $month);
        $this->assertSame($total, $invoice->fresh()->total_amount);
    }

    public function test_unknown_venue_fee_requires_a_linked_manual_confirmation(): void
    {
        $transfer = $this->transfer();
        $transfer->originalReservationRequest->lessonSlot->venue->update(['lesson_room_type' => null, 'name' => '未分類会場']);
        $this->actingAs($transfer->originalReservationRequest->lessonSlot->teacherProfile->user)
            ->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $invoice = app(MonthlyInvoiceGenerator::class)->generateForStudent($transfer->studentProfile, CarbonImmutable::parse('2026-09-01'));
        $admin = User::factory()->admin()->create();

        try {
            app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin, true);
            $this->fail('Unresolved fees must block confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        app(AddInvoiceAdjustment::class)->handle($invoice, $admin, 0, '会場に確認し追加なし', '未確定会場費の確認', $transfer->id);
        $confirmed = app(ConfirmMonthlyInvoice::class)->handle($invoice->fresh(), $admin, true);
        $this->assertSame('confirmed', $confirmed->status->value);
    }

    public function test_procedure_deadline_includes_the_tenth_but_not_the_eleventh(): void
    {
        $this->travelTo('2026-09-11 00:00:00');
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create();

        $this->actingAs($student->user)->post(route('student.membership-status-requests.store'), [
            'type' => 'pause', 'effective_on' => '2026-10-01',
        ])->assertSessionHasErrors('effective_on');

        $this->assertDatabaseCount('membership_status_requests', 0);
    }

    public function test_timetable_is_released_in_the_third_pause_month_with_history(): void
    {
        $this->travelTo('2026-11-30 10:00:00');
        $student = StudentProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create([
            'status' => EnrollmentStatus::Paused, 'weekday' => 1, 'starts_at_time' => '14:00', 'regular_week_numbers' => [1, 3],
        ]);
        $pause = MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Pause, 'status' => ApplicationStatus::Approved,
            'effective_on' => '2026-10-01', 'applied_at' => '2026-10-01',
        ]);
        $release = app(PausedScheduleRelease::class);
        $release->release($student);
        $this->assertSame(1, $enrollment->fresh()->weekday);

        $this->travelTo('2026-12-01 00:05');
        $release->release($student);
        $release->release($student);

        $this->assertNull($enrollment->fresh()->weekday);
        $this->assertSame(1, $pause->fresh()->released_schedule_snapshot[$enrollment->id]['weekday']);
    }

    public function test_non_admin_cannot_change_a_venue_billing_category(): void
    {
        $teacher = User::factory()->teacher()->create();
        $venue = Venue::factory()->create();

        $this->actingAs($teacher)->patch(route('staff.pricing-settings.venues.update', $venue), [
            'name' => 'studio34', 'lesson_room_type' => 'studio34',
        ])->assertForbidden();

        $this->assertNull($venue->fresh()->lesson_room_type);
    }

    public function test_staff_can_open_the_form_and_see_the_fee_breakdown(): void
    {
        $transfer = $this->transfer();
        $teacher = $transfer->originalReservationRequest->lessonSlot->teacherProfile->user;

        $this->actingAs($teacher)->get(route('staff.transfer-requests.show', $transfer))
            ->assertOk()->assertSee('会場キャンセル料')->assertSee('805円');
        $this->get(route('staff.transfer-requests.create', $transfer->originalReservationRequest))
            ->assertOk()->assertSee(route('staff.transfer-requests.store', $transfer->originalReservationRequest), false);
    }

    public function test_admin_can_set_venue_type_without_reclassifying_other_venues(): void
    {
        $admin = User::factory()->admin()->create();
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create(['name' => '有限会社 伊藤']);

        $this->actingAs($admin)->patch(route('staff.pricing-settings.venues.update', $venue), [
            'name' => '電子ドラムコース（別棟音楽室）', 'lesson_room_type' => 'music_room',
        ])->assertSessionHasNoErrors();

        $this->assertSame('music_room', $venue->fresh()->lesson_room_type);
        $this->assertNull($other->fresh()->lesson_room_type);
        $this->get(route('staff.pricing-settings.index'))->assertOk()->assertSee('会場名・教室規約の区分');
    }

    public function test_music_room_fee_is_one_thousand_in_the_enrollment_quote(): void
    {
        $transfer = $this->transfer();
        $enrollment = $transfer->originalReservationRequest->lessonEnrollment;
        $enrollment->venue->update(['lesson_room_type' => 'music_room']);

        $quote = app(LessonPricingService::class)->forEnrollment($enrollment->fresh(), '2026-09-20');

        $this->assertSame(1000, $quote->studioFeePerLesson);
        $this->assertSame(12000, $quote->estimatedMonthlyTotal);
    }

    public static function trialCancellationStates(): array
    {
        return ['before teacher confirmation' => ['pending', 0], 'after teacher confirmation' => ['approved', 805]];
    }

    #[DataProvider('trialCancellationStates')]
    public function test_trial_cancellation_fee_applies_only_after_confirmation(string $status, int $fee): void
    {
        $this->travelTo('2026-09-17 10:00');
        $venue = Venue::factory()->create(['name' => 'studio34', 'lesson_room_type' => 'studio34']);
        $slot = LessonSlot::factory()->forTrials()->for($venue)->create([
            'starts_at' => '2026-09-20 12:00', 'ends_at' => '2026-09-20 13:00',
        ]);
        $trial = TrialLessonRequest::factory()->for($slot)->create(['status' => $status]);

        app(CancelTrialLessonRequest::class)->handle($trial);

        $this->assertSame($fee, $trial->fresh()->cancellation_fee_amount);
        $this->assertSame('cancelled', $trial->fresh()->status->value);
    }

    private function transfer(): TransferRequest
    {
        $this->travelTo('2026-09-17 10:00');
        $student = StudentProfile::factory()->create();
        $venue = Venue::factory()->create(['name' => 'studio34', 'lesson_room_type' => 'studio34']);
        $slot = LessonSlot::factory()->for($venue)->create(['starts_at' => '2026-09-20 12:00', 'ends_at' => '2026-09-20 13:00']);
        $enrollment = LessonEnrollment::factory()->for($student)->create([
            'course_id' => $slot->course_id, 'venue_id' => $venue->id, 'monthly_lesson_limit' => 2, 'starts_on' => '2026-04-01',
        ]);
        PriceRate::factory()->create([
            'kind' => PriceRateKind::RegularLesson, 'pricing_category' => $enrollment->pricing_category,
            'monthly_lesson_count' => 2, 'amount' => 8000, 'effective_from' => '2026-01-01',
        ]);
        $original = ReservationRequest::factory()->for($student)->for($slot)->create([
            'lesson_enrollment_id' => $enrollment->id, 'status' => ReservationStatus::Approved,
            'studio_fee_amount' => 1610, 'studio_fee_priced_on' => '2026-09-20', 'lesson_entitlement_month' => '2026-09-01',
        ]);
        $target = LessonSlot::factory()->for($venue)->create([
            'teacher_profile_id' => $slot->teacher_profile_id, 'course_id' => $slot->course_id,
            'starts_at' => '2026-09-27 12:00', 'ends_at' => '2026-09-27 13:00',
        ]);

        return TransferRequest::factory()->for($student)->create([
            'original_reservation_request_id' => $original->id, 'requested_lesson_slot_id' => $target->id,
        ]);
    }
}
