<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ContractChangeType;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Enums\ReservationStatus;
use App\Models\ContractChangeRequest;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\LessonPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PricingContractIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_approved_lesson_type_change_creates_a_flex_contract_with_its_effective_price(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $this->seed();
        [$student, $enrollment] = $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();

        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::LessonType->value,
            'lesson_enrollment_id' => $enrollment->id,
            'lesson_type' => LessonType::Flex->value,
            'effective_on' => '2026-10-01',
        ])->assertRedirectToRoute('student.contract-change-requests.index', ['type' => ContractChangeType::LessonType->value]);
        $request = ContractChangeRequest::query()->firstOrFail();

        $this->actingAs($admin)->patch(route('staff.contract-change-requests.update', $request), [
            'decision' => ApplicationStatus::Approved->value,
        ])->assertRedirectToRoute('staff.contract-change-requests.show', $request);

        $replacement = LessonEnrollment::query()->where('supersedes_lesson_enrollment_id', $enrollment->id)->firstOrFail();
        $quote = app(LessonPricingService::class)->forEnrollment($replacement, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame(LessonType::Flex, $replacement->lesson_type);
        $this->assertNull($replacement->weekday);
        $this->assertNull($replacement->starts_at_time);
        $this->assertSame(11500, $quote->lessonFeeTotal);
        $this->assertSame(LessonType::Regular, $enrollment->fresh()->lesson_type);
    }

    public function test_approved_pricing_category_change_preserves_the_standard_contract_history(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $this->seed();
        [$student, $enrollment] = $this->studentWithEnrollment();
        $admin = User::factory()->admin()->create();

        $this->actingAs($student->user)->post(route('student.contract-change-requests.store'), [
            'type' => ContractChangeType::PricingCategory->value,
            'lesson_enrollment_id' => $enrollment->id,
            'pricing_category' => PricingCategory::Junior->value,
            'effective_on' => '2026-10-01',
        ]);
        $request = ContractChangeRequest::query()->firstOrFail();
        $this->actingAs($admin)->patch(route('staff.contract-change-requests.update', $request), [
            'decision' => ApplicationStatus::Approved->value,
        ]);

        $replacement = LessonEnrollment::query()->where('supersedes_lesson_enrollment_id', $enrollment->id)->firstOrFail();
        $quote = app(LessonPricingService::class)->forEnrollment($replacement, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame(PricingCategory::Junior, $replacement->pricing_category);
        $this->assertSame(10000, $quote->lessonFeeTotal);
        $this->assertSame(PricingCategory::Standard, $enrollment->fresh()->pricing_category);
    }

    public function test_approved_transfer_moves_one_studio_fee_snapshot_to_the_resulting_reservation(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $this->seed();
        [$student, $enrollment, $teacher] = $this->studentWithEnrollment();
        $originalSlot = $this->slot($enrollment, $teacher, '2026-09-20 10:00:00');
        $targetSlot = $this->slot($enrollment, $teacher, '2026-10-05 10:00:00');
        $original = ReservationRequest::factory()->for($student)->for($originalSlot)->for($enrollment, 'lessonEnrollment')->create([
            'status' => ReservationStatus::Approved,
            'studio_fee_amount' => 1610,
            'studio_fee_priced_on' => '2026-09-20',
        ]);
        $transfer = TransferRequest::factory()->create([
            'student_profile_id' => $student->id,
            'original_reservation_request_id' => $original->id,
            'requested_lesson_slot_id' => $targetSlot->id,
        ]);

        $this->actingAs($teacher->user)->patch(route('staff.transfer-requests.update', $transfer), [
            'decision' => ApplicationStatus::Approved->value,
        ])->assertRedirectToRoute('staff.transfer-requests.show', $transfer);

        $resulting = $transfer->fresh()->resultingReservationRequest;
        $this->assertNull($original->fresh()->studio_fee_amount);
        $this->assertSame(1610, $resulting->studio_fee_amount);
        $this->assertSame(1610, (int) $student->reservationRequests()->sum('studio_fee_amount'));
    }

    public function test_teacher_sees_inactive_flex_warning_only_for_an_authorized_student(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        [$student, $enrollment, $teacher] = $this->studentWithEnrollment();
        $enrollment->update(['lesson_type' => LessonType::Flex]);
        $unrelatedTeacher = TeacherProfile::factory()->create();

        $this->actingAs($teacher->user)
            ->get(route('staff.students.show', $student))
            ->assertOk()
            ->assertSee('2ヶ月以上レッスンがありません');
        $this->actingAs($unrelatedTeacher->user)
            ->get(route('staff.students.show', $student))
            ->assertForbidden();
    }

    public function test_student_dashboard_shows_current_contract_and_estimated_monthly_price(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $this->seed();
        [$student] = $this->studentWithEnrollment();

        $this->actingAs($student->user)
            ->get(route('student.dashboard', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('レギュラーレッスン・一般')
            ->assertSee('¥11,000')
            ->assertSee('¥1,610')
            ->assertSee('¥14,220');
    }

    public function test_reservation_approval_snapshots_the_studio_fee_effective_on_the_lesson_date(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $this->seed();
        [$student, $enrollment, $teacher] = $this->studentWithEnrollment();
        $slot = $this->slot($enrollment, $teacher, '2026-09-20 10:00:00');
        $reservation = ReservationRequest::factory()->for($student)->for($slot)->for($enrollment, 'lessonEnrollment')->create([
            'status' => ReservationStatus::Pending,
            'lesson_entitlement_month' => '2026-09-01',
        ]);

        $this->actingAs($teacher->user)->patch(route('staff.reservations.update', $reservation), [
            'decision' => ReservationStatus::Approved->value,
        ])->assertRedirectToRoute('staff.reservations.index');

        $this->assertSame(1610, $reservation->fresh()->studio_fee_amount);
        $this->assertSame('2026-09-20', $reservation->fresh()->studio_fee_priced_on->toDateString());
    }

    /** @return array{StudentProfile, LessonEnrollment, TeacherProfile} */
    private function studentWithEnrollment(): array
    {
        $student = StudentProfile::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->for($teacher)->create([
            'lesson_type' => LessonType::Regular,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_limit' => 2,
            'starts_on' => '2026-01-01',
        ]);

        return [$student, $enrollment, $teacher];
    }

    private function slot(LessonEnrollment $enrollment, TeacherProfile $teacher, string $startsAt): LessonSlot
    {
        $starts = CarbonImmutable::parse($startsAt);

        return LessonSlot::factory()->for($teacher)->for($enrollment->course)->create([
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'capacity' => 2,
        ]);
    }
}
