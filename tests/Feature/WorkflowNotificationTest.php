<?php

namespace Tests\Feature;

use App\Actions\CreateContractChangeRequest;
use App\Actions\CreateMembershipStatusRequest;
use App\Actions\CreatePaymentMethodChangeRequest;
use App\Actions\CreatePersonalInformationChangeRequest;
use App\Actions\ReviewContractChangeRequest;
use App\Actions\ReviewMembershipStatusRequest;
use App\Actions\ReviewPaymentMethodChangeRequest;
use App\Actions\ReviewPersonalInformationChangeRequest;
use App\Enums\ApplicationStatus;
use App\Enums\AttendanceNoticeType;
use App\Enums\ContractChangeType;
use App\Enums\InquiryCategory;
use App\Enums\InquiryStatus;
use App\Enums\MembershipRequestType;
use App\Enums\PaymentMethod;
use App\Enums\PriceRateKind;
use App\Enums\ReservationStatus;
use App\Models\Course;
use App\Models\Inquiry;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\PriceRate;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\AttendanceNoticeNotification;
use App\Notifications\ContractChangeApplicationNotification;
use App\Notifications\InquiryNotification;
use App\Notifications\MembershipStatusApplicationNotification;
use App\Notifications\PaymentMethodChangeApplicationNotification;
use App\Notifications\PersonalInformationChangeApplicationNotification;
use App\Notifications\ReservationApplicationNotification;
use App\Notifications\ReservationCancelledNotification;
use App\Notifications\TransferApplicationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkflowNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reservation_submission_approval_rejection_and_cancellation_notify_the_correct_recipients(): void
    {
        PriceRate::factory()->create(['kind' => PriceRateKind::StudioPerLesson, 'pricing_category' => null, 'monthly_lesson_count' => null, 'amount' => 1610, 'effective_from' => '2026-01-01']);
        $this->travelTo('2026-09-13 10:00:00');
        [$student, $teacher, $admin, $enrollment, $slot] = $this->lessonContext();
        Notification::fake();

        $this->actingAs($student->user)
            ->post(route('student.reservations.store', $slot))
            ->assertRedirectToRoute('student.reservations.index');

        $reservation = ReservationRequest::query()->firstOrFail();
        Notification::assertSentTo($teacher->user, ReservationApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ReservationStatus::Pending);
        Notification::assertSentTo($admin, ReservationApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ReservationStatus::Pending);

        $this->actingAs($teacher->user)
            ->patch(route('staff.reservations.update', $reservation), ['decision' => ReservationStatus::Approved->value])
            ->assertRedirectToRoute('staff.reservations.index');

        Notification::assertSentTo($student->user, ReservationApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ReservationStatus::Approved);

        $this->actingAs($student->user)
            ->delete(route('student.reservations.destroy', $reservation), ['cancellation_reason' => '都合が悪くなりました'])
            ->assertRedirectToRoute('student.reservations.index');

        Notification::assertSentTo($teacher->user, ReservationCancelledNotification::class);
        Notification::assertSentTo($admin, ReservationCancelledNotification::class);

        $rejectedSlot = $this->slot($teacher, $enrollment->course, $enrollment->venue, '2026-09-22 14:00:00');
        $rejected = ReservationRequest::factory()->for($student)->for($rejectedSlot)->for($enrollment)->create();
        $this->actingAs($teacher->user)->patch(route('staff.reservations.update', $rejected), [
            'decision' => ReservationStatus::Rejected->value,
            'staff_note' => '講師都合です',
        ]);

        Notification::assertSentTo($student->user, ReservationApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ReservationStatus::Rejected);
    }

    public function test_absence_lateness_and_transfer_events_notify_staff_and_student(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        [$student, $teacher, $admin, $enrollment, $slot] = $this->lessonContext();
        $reservation = ReservationRequest::factory()->for($student)->for($slot)->for($enrollment)->create(['status' => ReservationStatus::Approved, 'studio_fee_amount' => 1610, 'studio_fee_priced_on' => $slot->starts_at->toDateString()]);
        Notification::fake();

        $this->actingAs($student->user)->put(route('student.attendance-notices.store', $reservation), [
            'type' => AttendanceNoticeType::Absence->value,
            'notes' => '体調不良です',
        ])->assertRedirectToRoute('student.attendance-notices.index');
        $this->actingAs($student->user)->put(route('student.attendance-notices.store', $reservation), [
            'type' => AttendanceNoticeType::Late->value,
            'late_minutes' => 15,
            'expected_arrival_time' => '18:15',
        ])->assertRedirectToRoute('student.attendance-notices.index');

        Notification::assertSentToTimes($teacher->user, AttendanceNoticeNotification::class, 2);
        Notification::assertSentToTimes($admin, AttendanceNoticeNotification::class, 2);

        $targetSlot = $this->slot($teacher, $enrollment->course, $enrollment->venue, '2026-09-24 18:00:00');
        $this->actingAs($student->user)->post(route('student.transfer-requests.store', $reservation), [
            'requested_lesson_slot_id' => $targetSlot->id,
            'reason' => '学校行事',
        ])->assertRedirectToRoute('student.transfer-requests.index');
        $transfer = TransferRequest::query()->firstOrFail();

        Notification::assertSentTo($teacher->user, TransferApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ApplicationStatus::Pending);
        Notification::assertSentTo($admin, TransferApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ApplicationStatus::Pending);

        $this->actingAs($teacher->user)->patch(route('staff.transfer-requests.update', $transfer), [
            'decision' => ApplicationStatus::Approved->value,
        ])->assertRedirectToRoute('staff.transfer-requests.show', $transfer);

        Notification::assertSentTo($student->user, TransferApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ApplicationStatus::Approved);

        $otherOriginal = ReservationRequest::factory()
            ->for($student)
            ->for($this->slot($teacher, $enrollment->course, $enrollment->venue, '2026-09-28 18:00:00'))
            ->for($enrollment)
            ->create(['status' => ReservationStatus::Approved]);
        $rejectedTransfer = TransferRequest::factory()->for($student)->create([
            'original_reservation_request_id' => $otherOriginal->id,
            'requested_lesson_slot_id' => $this->slot($teacher, $enrollment->course, $enrollment->venue, '2026-09-30 18:00:00')->id,
        ]);
        $this->actingAs($teacher->user)->patch(route('staff.transfer-requests.update', $rejectedTransfer), [
            'decision' => ApplicationStatus::Rejected->value,
            'staff_note' => '振替先を調整してください',
        ])->assertRedirectToRoute('staff.transfer-requests.show', $rejectedTransfer);

        Notification::assertSentTo($student->user, TransferApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ApplicationStatus::Rejected);
    }

    public function test_membership_contract_personal_payment_and_inquiry_events_notify_the_expected_users(): void
    {
        [$student, $teacher, $admin, $enrollment] = $this->lessonContext();
        Notification::fake();

        $createMembership = app(CreateMembershipStatusRequest::class);
        $reviewMembership = app(ReviewMembershipStatusRequest::class);
        foreach ([MembershipRequestType::Pause, MembershipRequestType::Withdraw] as $type) {
            $membership = $createMembership->handle($student, $type, CarbonImmutable::parse('2026-10-01'), null, null);
            $reviewMembership->handle($membership, $admin, ApplicationStatus::Rejected, '確認が必要です');
        }
        $enrollment->update(['status' => 'paused']);
        $resume = $createMembership->handle($student, MembershipRequestType::Resume, CarbonImmutable::parse('2026-10-01'), null, null);
        $reviewMembership->handle($resume, $admin, ApplicationStatus::Approved, null);

        Notification::assertSentToTimes($teacher->user, MembershipStatusApplicationNotification::class, 3);
        Notification::assertSentToTimes($admin, MembershipStatusApplicationNotification::class, 3);
        Notification::assertSentToTimes($student->user, MembershipStatusApplicationNotification::class, 3);

        $enrollment->update(['status' => 'active']);
        $contract = app(CreateContractChangeRequest::class)->handle($student, [
            'type' => ContractChangeType::MonthlyLessons->value,
            'lesson_enrollment_id' => $enrollment->id,
            'monthly_lesson_limit' => 2,
            'effective_on' => '2026-10-01',
        ]);
        app(ReviewContractChangeRequest::class)->handle($contract, $admin, ApplicationStatus::Rejected, '日程調整中です');

        $personal = app(CreatePersonalInformationChangeRequest::class)->handle($student, [
            'name' => '変更後氏名',
            'email' => 'after@example.test',
            'phone' => $student->phone,
            'address' => $student->address,
        ]);
        app(ReviewPersonalInformationChangeRequest::class)->handle($personal, $admin, ApplicationStatus::Approved, null);

        $payment = app(CreatePaymentMethodChangeRequest::class)->handle($student, [
            'lesson_enrollment_id' => $enrollment->id,
            'requested_method' => PaymentMethod::Cash->value,
        ]);
        app(ReviewPaymentMethodChangeRequest::class)->handle($payment, $admin, ApplicationStatus::Rejected, '確認中です');

        $this->actingAs($student->user->fresh())->post(route('student.inquiries.store'), [
            'category' => InquiryCategory::Lesson->value,
            'subject' => 'レッスンについて',
            'body' => '確認したいことがあります。',
        ])->assertRedirectToRoute('student.inquiries.index');
        $inquiry = Inquiry::query()->firstOrFail();
        $this->actingAs($admin)->patch(route('staff.inquiries.update', $inquiry), ['status' => InquiryStatus::Resolved->value]);

        Notification::assertSentTo($teacher->user, ContractChangeApplicationNotification::class);
        Notification::assertSentTo($admin, PersonalInformationChangeApplicationNotification::class);
        Notification::assertSentTo($admin, PaymentMethodChangeApplicationNotification::class);
        Notification::assertSentTo($teacher->user, InquiryNotification::class, fn ($notification): bool => $notification->eventStatus === InquiryStatus::Open);
        Notification::assertSentTo($student->user, ContractChangeApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ApplicationStatus::Rejected);
        Notification::assertSentTo($student->user, PersonalInformationChangeApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ApplicationStatus::Approved);
        Notification::assertSentTo($student->user, PaymentMethodChangeApplicationNotification::class, fn ($notification): bool => $notification->eventStatus === ApplicationStatus::Rejected);
        Notification::assertSentTo($student->user, InquiryNotification::class, fn ($notification): bool => $notification->eventStatus === InquiryStatus::Resolved);
    }

    public function test_sensitive_personal_and_payment_values_are_not_rendered_in_email(): void
    {
        [$student, , $admin, $enrollment] = $this->lessonContext();
        $personal = PersonalInformationChangeRequest::create([
            'student_profile_id' => $student->id,
            'before_values' => ['email' => 'private-before@example.test'],
            'after_values' => ['email' => 'private-after@example.test', 'address' => '東京都秘密町1-2-3'],
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
        ]);
        $payment = PaymentMethodChangeRequest::create([
            'student_profile_id' => $student->id,
            'lesson_enrollment_id' => $enrollment->id,
            'requested_method' => PaymentMethod::CreditCard,
            'student_note' => '4111111111111111 / 1234567',
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
        ]);

        $personalHtml = (new PersonalInformationChangeApplicationNotification($personal, ApplicationStatus::Pending))->toMail($admin)->render();
        $paymentHtml = (new PaymentMethodChangeApplicationNotification($payment, ApplicationStatus::Pending))->toMail($admin)->render();

        $this->assertStringNotContainsString('private-after@example.test', $personalHtml);
        $this->assertStringNotContainsString('東京都秘密町1-2-3', $personalHtml);
        $this->assertStringNotContainsString('4111111111111111', $paymentHtml);
        $this->assertStringNotContainsString('1234567', $paymentHtml);
    }

    public function test_missing_email_and_disabled_preferences_are_skipped_without_an_error(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        [$student, $teacher, $admin, , $slot] = $this->lessonContext();
        $teacher->user->update(['email' => null]);
        $admin->update(['notification_preferences' => ['email' => false]]);
        Notification::fake();

        $this->actingAs($student->user)->post(route('student.reservations.store', $slot))->assertRedirectToRoute('student.reservations.index');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('reservation_requests', 1);
    }

    public function test_notification_queue_failure_does_not_roll_back_the_reservation(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        [$student, $teacher, , , $slot] = $this->lessonContext();
        $teacher->user->update(['email' => 'teacher@example.test']);
        config(['queue.default' => 'unavailable']);

        $this->actingAs($student->user)->post(route('student.reservations.store', $slot))->assertRedirectToRoute('student.reservations.index');

        $this->assertDatabaseHas('reservation_requests', ['student_profile_id' => $student->id, 'lesson_slot_id' => $slot->id]);
    }

    public function test_unauthorized_review_sends_no_notification(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $otherTeacher = TeacherProfile::factory()->create();
        $reservation = ReservationRequest::factory()->for(LessonSlot::factory()->for($teacher))->create();
        Notification::fake();

        $this->actingAs($otherTeacher->user)->patch(route('staff.reservations.update', $reservation), [
            'decision' => ReservationStatus::Approved->value,
        ])->assertForbidden();

        Notification::assertNothingSent();
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    /** @return array{StudentProfile, TeacherProfile, User, LessonEnrollment, LessonSlot} */
    private function lessonContext(): array
    {
        $teacher = TeacherProfile::factory()->create();
        $admin = User::factory()->admin()->create();
        $student = StudentProfile::factory()->create();
        $course = Course::factory()->create();
        $venue = Venue::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->for($course)->create([
            'teacher_profile_id' => $teacher->id,
            'venue_id' => $venue->id,
            'starts_on' => '2026-09-01',
        ]);
        $slot = $this->slot($teacher, $course, $venue, '2026-09-20 18:00:00');

        return [$student, $teacher, $admin, $enrollment, $slot];
    }

    private function slot(TeacherProfile $teacher, Course $course, Venue $venue, string $startsAt): LessonSlot
    {
        $starts = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return LessonSlot::factory()->for($teacher)->for($course)->for($venue)->create([
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
        ]);
    }
}
