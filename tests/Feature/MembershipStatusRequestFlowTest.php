<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Models\LessonEnrollment;
use App\Models\MembershipStatusRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MembershipStatusRequestFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_student_can_submit_a_pause_request(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Active]);

        $this->actingAs($student->user)
            ->post(route('student.membership-status-requests.store'), [
                'type' => MembershipRequestType::Pause->value,
                'pause_months' => 2,
                'effective_on' => '2026-10-01',
                'reason' => '仕事が忙しいため',
            ])
            ->assertRedirectToRoute('student.membership-status-requests.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('membership_status_requests', [
            'student_profile_id' => $student->id,
            'type' => MembershipRequestType::Pause->value,
            'pause_months' => 2,
            'status' => ApplicationStatus::Pending->value,
        ]);
        $this->assertSame('2026-10-01', MembershipStatusRequest::query()->firstOrFail()->effective_on->toDateString());
    }

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    public function test_pause_duration_is_saved_and_visible_to_student_and_admin(int $months): void
    {
        $this->travelTo('2026-10-10 23:59:00');
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create();
        $this->actingAs($student->user)->post(route('student.membership-status-requests.store'), [
            'type' => 'pause', 'effective_on' => '2026-11-01', 'pause_months' => $months,
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('student.membership-status-requests.index');
        $request = MembershipStatusRequest::query()->sole();
        $this->assertSame($months, $request->pause_months);
        $this->get(route('student.membership-status-requests.index'))->assertOk()->assertSee('休会期間 '.$months.'か月');
        $this->actingAs(User::factory()->admin()->create())->get(route('staff.membership-status-requests.show', $request))
            ->assertOk()->assertSee($months.'か月');
    }

    #[TestWith([null])]
    #[TestWith([0])]
    #[TestWith([4])]
    public function test_pause_requires_a_duration_between_one_and_three_months(?int $months): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create();
        $this->actingAs($student->user)->post(route('student.membership-status-requests.store'), [
            'type' => 'pause', 'effective_on' => '2026-11-01', 'pause_months' => $months,
        ])->assertSessionHasErrors(['pause_months' => '休会期間を1〜3か月から選択してください。']);
        $this->assertDatabaseCount('membership_status_requests', 0);
    }

    #[TestWith(['pause'])]
    #[TestWith(['withdraw'])]
    public function test_pause_and_withdraw_reject_month_after_deadline(string $type): void
    {
        $this->travelTo('2026-10-11 00:00:00');
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create();
        $this->actingAs($student->user)->post(route('student.membership-status-requests.store'), [
            'type' => $type, 'effective_on' => '2026-11-01', 'pause_months' => 1,
        ])->assertSessionHasErrors('effective_on');
        $this->assertDatabaseCount('membership_status_requests', 0);
    }

    public function test_student_cannot_submit_a_second_pending_membership_request(): void
    {
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Active]);
        MembershipStatusRequest::factory()->for($student)->create();

        $this->actingAs($student->user)
            ->post(route('student.membership-status-requests.store'), [
                'type' => MembershipRequestType::Withdraw->value,
                'effective_on' => now()->addMonth()->toDateString(),
            ])
            ->assertSessionHasErrors([
                'type' => '現在、承認待ちまたは適用待ちの在籍申請があります。',
            ]);

        $this->assertDatabaseCount('membership_status_requests', 1);
    }

    public function test_resume_request_requires_a_paused_enrollment(): void
    {
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Active]);

        $this->actingAs($student->user)
            ->post(route('student.membership-status-requests.store'), [
                'type' => MembershipRequestType::Resume->value,
                'effective_on' => now()->addMonth()->toDateString(),
            ])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('membership_status_requests', 0);
    }

    public function test_student_cannot_submit_while_an_approved_future_request_is_waiting_to_apply(): void
    {
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Active]);
        MembershipStatusRequest::factory()->for($student)->create([
            'status' => ApplicationStatus::Approved,
            'effective_on' => now()->addMonth()->toDateString(),
            'applied_at' => null,
        ]);

        $this->actingAs($student->user)
            ->post(route('student.membership-status-requests.store'), [
                'type' => MembershipRequestType::Withdraw->value,
                'effective_on' => now()->addMonths(2)->toDateString(),
            ])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('membership_status_requests', 1);
    }

    public function test_effective_date_cannot_be_in_the_past(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Active]);

        $this->actingAs($student->user)
            ->post(route('student.membership-status-requests.store'), [
                'type' => MembershipRequestType::Pause->value,
                'pause_months' => 2,
                'effective_on' => '2026-09-11',
            ])
            ->assertSessionHasErrors('effective_on');

        $this->assertDatabaseCount('membership_status_requests', 0);
    }

    public function test_approval_applies_a_due_pause_request_and_preserves_previous_status(): void
    {
        $this->travelTo('2026-09-12 10:00:00');
        $student = StudentProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Active]);
        $membershipRequest = MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Pause,
            'effective_on' => '2026-09-12',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('staff.membership-status-requests.update', $membershipRequest), [
                'decision' => ApplicationStatus::Approved->value,
                'staff_note' => '承認しました',
            ])
            ->assertRedirectToRoute('staff.membership-status-requests.show', $membershipRequest);

        $this->assertSame(EnrollmentStatus::Paused, $enrollment->fresh()->status);
        $this->assertSame(ApplicationStatus::Approved, $membershipRequest->fresh()->status);
        $this->assertSame([$enrollment->id => EnrollmentStatus::Active->value], $membershipRequest->fresh()->previous_enrollment_statuses);
        $this->assertNotNull($membershipRequest->fresh()->applied_at);
    }

    public function test_future_approved_request_is_applied_by_the_due_date_command(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $student = StudentProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Active]);
        $membershipRequest = MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Withdraw,
            'effective_on' => '2026-10-01',
        ]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patch(route('staff.membership-status-requests.update', $membershipRequest), [
            'decision' => ApplicationStatus::Approved->value,
        ]);
        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);
        $this->assertNull($membershipRequest->fresh()->applied_at);
        $this->travelTo('2026-10-01 00:05:00');

        $this->artisan('membership-requests:apply-approved')
            ->expectsOutput('Applied 1 membership status request(s).')
            ->assertSuccessful();

        $this->assertSame(EnrollmentStatus::Ended, $enrollment->fresh()->status);
        $this->assertSame('2026-10-01', $enrollment->fresh()->ends_on->toDateString());
        $this->assertNotNull($membershipRequest->fresh()->applied_at);
    }

    public function test_rejected_request_does_not_change_enrollment_or_delete_history(): void
    {
        $student = StudentProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Paused]);
        $membershipRequest = MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Resume,
        ]);
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)
            ->patch(route('staff.membership-status-requests.update', $membershipRequest), [
                'decision' => ApplicationStatus::Rejected->value,
            ])
            ->assertRedirectToRoute('staff.membership-status-requests.show', $membershipRequest);

        $this->assertSame(EnrollmentStatus::Paused, $enrollment->fresh()->status);
        $this->assertSame(ApplicationStatus::Rejected, $membershipRequest->fresh()->status);
        $this->assertModelExists($membershipRequest);
    }

    public function test_student_cannot_review_a_membership_request(): void
    {
        $student = StudentProfile::factory()->create();
        $membershipRequest = MembershipStatusRequest::factory()->for($student)->create();

        $this->actingAs($student->user)
            ->patch(route('staff.membership-status-requests.update', $membershipRequest), [
                'decision' => ApplicationStatus::Approved->value,
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::Pending, $membershipRequest->fresh()->status);
    }

    public function test_approval_resumes_a_paused_enrollment(): void
    {
        $student = StudentProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Paused]);
        $membershipRequest = MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Resume,
            'effective_on' => today()->toDateString(),
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('staff.membership-status-requests.update', $membershipRequest), [
            'decision' => ApplicationStatus::Approved->value,
        ]);

        $this->assertSame(EnrollmentStatus::Active, $enrollment->fresh()->status);
        $this->assertNotNull($membershipRequest->fresh()->applied_at);
    }
}
