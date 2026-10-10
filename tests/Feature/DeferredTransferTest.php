<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\InvoiceItemType;
use App\Enums\MembershipRequestType;
use App\Enums\MonthlyInvoiceStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\Venue;
use App\Notifications\TransferApplicationNotification;
use App\Services\MonthlyInvoiceGenerator;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DeferredTransferTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_can_defer_without_a_date_and_teacher_can_schedule_in_december(): void
    {
        Notification::fake();
        $original = $this->original();
        $student = $original->studentProfile;
        $teacher = $original->lessonSlot->teacherProfile;
        $this->actingAs($student->user)->get(route('student.transfer-requests.create', $original))->assertOk()->assertSee('振替日を保留して申請する');
        $this->post(route('student.transfer-requests.store', $original), ['defer_schedule' => 1])->assertSessionHasNoErrors();
        $transfer = TransferRequest::sole();
        $this->assertNull($transfer->requested_lesson_slot_id);
        $this->assertSame(ReservationStatus::Approved, $original->fresh()->status);
        $this->assertDatabaseCount('lesson_slots', 1);
        $this->get(route('student.transfer-requests.index'))->assertOk()->assertSee('保留申請中');
        $this->post(route('student.transfer-requests.store', $original), ['defer_schedule' => 1])->assertSessionHasErrors('reservation');
        Notification::assertSentTo($teacher->user, TransferApplicationNotification::class);
        $this->actingAs($teacher->user)->get(route('staff.transfer-requests.deferred'))->assertOk()->assertSee($student->user->name);
        $this->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $transfer->refresh();
        $this->assertTrue($transfer->isAwaitingSchedule());
        $this->assertSame(ReservationStatus::Cancelled, $original->fresh()->status);
        $snapshot = $transfer->terms_snapshot;
        $this->assertSame(0, $snapshot['total']);
        $this->get(route('staff.transfer-requests.show', $transfer))->assertOk()->assertSee('保留コマの日程を設定');
        $usage = app(MonthlyLessonUsageCalculator::class)->calculate($student, CarbonImmutable::parse('2026-11-01'));
        $this->assertSame(1, $usage->used);
        $this->assertSame('deferred', $usage->items->sole()['category']);
        $generator = app(MonthlyInvoiceGenerator::class);
        $invoice = $generator->generateForStudent($student, CarbonImmutable::parse('2026-11-01'));
        $this->assertEquals(1610, $invoice->items()->where('type', InvoiceItemType::StudioFee)->sum('amount'));
        $heldMail = new TransferApplicationNotification($transfer, ApplicationStatus::Approved);
        $this->assertStringContainsString('日程保留', $heldMail->toMail($student->user)->subject);
        $this->travelTo('2026-11-25 10:00:00');
        $this->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => '2026-12-10T13:00'])->assertSessionHasNoErrors()->assertRedirectToRoute('staff.transfer-requests.show', $transfer);
        $transfer->refresh();
        $this->assertFalse($transfer->isAwaitingSchedule());
        $this->assertSame($snapshot, $transfer->terms_snapshot);
        $this->assertSame('2026-11-01', $transfer->resultingReservationRequest->lesson_entitlement_month->toDateString());
        $this->assertSame('2026-12-10 13:00', $transfer->requestedLessonSlot->starts_at->format('Y-m-d H:i'));
        $this->assertSame(ReservationStatus::Approved, $transfer->resultingReservationRequest->status);
        $this->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => '2026-12-11T13:00'])->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('lesson_slots', 2);
        $this->get(route('staff.transfer-requests.deferred'))->assertOk()->assertDontSee($student->user->name);
        $this->actingAs($student->user)->get(route('student.transfer-requests.index'))->assertOk()->assertSee('振替確定');
        $invoice = $generator->generateForStudent($student, CarbonImmutable::parse('2026-11-01'));
        $this->assertEquals(1610, $invoice->items()->where('type', InvoiceItemType::StudioFee)->sum('amount'));
        $this->assertSame(1, app(MonthlyLessonUsageCalculator::class)->calculate($student, CarbonImmutable::parse('2026-11-01'))->used);
        $this->assertSame(0, app(MonthlyLessonUsageCalculator::class)->calculate($student, CarbonImmutable::parse('2026-12-01'))->used);
        Notification::assertSentTo($student->user, TransferApplicationNotification::class);
    }

    public function test_other_teachers_and_students_cannot_assign_or_view_the_hold(): void
    {
        Notification::fake();
        $transfer = $this->hold();
        $other = TeacherProfile::factory()->create();
        $this->actingAs($other->user)->get(route('staff.transfer-requests.deferred'))->assertOk()->assertDontSee($transfer->studentProfile->user->name);
        $this->get(route('staff.transfer-requests.show', $transfer))->assertForbidden();
        $this->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => '2026-12-10T13:00'])->assertForbidden();
        $this->actingAs($transfer->studentProfile->user)->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => '2026-12-10T13:00'])->assertForbidden();
        $this->get(route('student.reservations.show', $transfer->originalReservationRequest))->assertOk()->assertSee('日程保留中');
    }

    #[TestWith(['2026-12-10T13:00'])]
    #[TestWith(['2026-12-10T14:00'])]
    public function test_overlap_and_travel_buffer_reject_assignment_without_orphan_slots(string $date): void
    {
        Notification::fake();
        $transfer = $this->hold();
        $teacher = $transfer->originalReservationRequest->lessonSlot->teacherProfile;
        $busy = LessonSlot::factory()->for($teacher)->for(Venue::factory()->create(['lesson_room_type' => 'music_room']))->create([
            'starts_at' => '2026-12-10 13:00', 'ends_at' => '2026-12-10 14:00',
        ]);
        ReservationRequest::factory()->for($busy)->create(['status' => ReservationStatus::Approved]);
        $before = LessonSlot::count();
        $this->actingAs($teacher->user)->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => $date])->assertSessionHasErrors('starts_at');
        $this->assertSame($before, LessonSlot::count());
        $this->assertTrue($transfer->fresh()->isAwaitingSchedule());
    }

    public function test_pause_month_cannot_receive_a_held_lesson(): void
    {
        Notification::fake();
        $transfer = $this->hold();
        MembershipStatusRequest::factory()->for($transfer->studentProfile)->create([
            'type' => MembershipRequestType::Pause, 'status' => ApplicationStatus::Approved, 'effective_on' => '2026-12-01',
        ]);
        $this->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => '2026-12-10T13:00'])->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('lesson_slots', 1);
    }

    public function test_hold_requires_timely_teacher_approval_and_rejection_preserves_original(): void
    {
        Notification::fake();
        $original = $this->original();
        $this->actingAs($original->studentProfile->user)->post(route('student.transfer-requests.store', $original), ['defer_schedule' => 1])->assertSessionHasNoErrors();
        $transfer = TransferRequest::sole();
        $this->travelTo('2026-11-09 12:00:01');
        $this->actingAs($original->lessonSlot->teacherProfile->user)->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])->assertSessionHasErrors('transfer_request');
        $this->assertSame(ReservationStatus::Approved, $original->fresh()->status);
        $this->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Approved, $original->fresh()->status);
        $this->assertSame(ApplicationStatus::Rejected, $transfer->fresh()->status);
    }

    public function test_electronic_45_minute_hold_can_start_at_quarter_past(): void
    {
        Notification::fake();
        $transfer = $this->hold(true);
        $this->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => '2026-12-10T13:15'])->assertSessionHasNoErrors();
        $this->assertSame('14:00', $transfer->fresh()->requestedLessonSlot->ends_at->format('H:i'));
        $this->assertSame(1000, $transfer->fresh()->resultingReservationRequest->studio_fee_amount);
    }

    #[TestWith(['2026-12-10T13:15'])]
    #[TestWith(['2026-12-10T20:30'])]
    #[TestWith(['2026-10-10T13:00'])]
    public function test_invalid_studio_time_does_not_consume_the_hold(string $date): void
    {
        Notification::fake();
        $transfer = $this->hold();
        $this->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => $date])->assertSessionHasErrors('starts_at');
        $this->assertTrue($transfer->fresh()->isAwaitingSchedule());
        $this->assertDatabaseCount('lesson_slots', 1);
    }

    public function test_scheduling_does_not_rewrite_confirmed_invoice(): void
    {
        Notification::fake();
        $transfer = $this->hold();
        $generator = app(MonthlyInvoiceGenerator::class);
        $invoice = $generator->generateForStudent($transfer->studentProfile, CarbonImmutable::parse('2026-11-01'));
        $invoice->update(['status' => MonthlyInvoiceStatus::Confirmed]);
        $items = $invoice->items()->get()->toArray();
        $this->post(route('staff.transfer-requests.schedule', $transfer), ['starts_at' => '2026-12-10T13:00'])->assertSessionHasNoErrors();
        $generator->generateForStudent($transfer->studentProfile, CarbonImmutable::parse('2026-11-01'));
        $this->assertSame($items, $invoice->items()->get()->toArray());
    }

    private function hold(bool $electronic = false): TransferRequest
    {
        $original = $this->original($electronic);
        $this->actingAs($original->studentProfile->user)->post(route('student.transfer-requests.store', $original), ['defer_schedule' => 1])->assertSessionHasNoErrors();
        $transfer = TransferRequest::sole();
        $this->actingAs($original->lessonSlot->teacherProfile->user)->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])->assertSessionHasNoErrors();

        return $transfer->fresh();
    }

    private function original(bool $electronic = false): ReservationRequest
    {
        $this->travelTo('2026-11-01 10:00:00');
        $student = StudentProfile::factory()->create();
        $slot = LessonSlot::factory()->for(Venue::factory()->create(['lesson_room_type' => $electronic ? 'music_room' : 'studio34']))->create([
            'starts_at' => '2026-11-10 13:00:00', 'ends_at' => $electronic ? '2026-11-10 13:45:00' : '2026-11-10 14:00:00',
        ]);
        $enrollment = LessonEnrollment::factory()->for($student)->create(['course_id' => $slot->course_id, 'starts_on' => '2026-04-01']);

        return ReservationRequest::factory()->for($student)->for($slot)->create([
            'lesson_enrollment_id' => $enrollment->id, 'lesson_entitlement_month' => '2026-11-01',
            'status' => ReservationStatus::Approved, 'studio_fee_amount' => $electronic ? 1000 : 1610, 'studio_fee_priced_on' => '2026-11-10',
        ]);
    }
}
