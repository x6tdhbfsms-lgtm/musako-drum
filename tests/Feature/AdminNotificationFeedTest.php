<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipRequestType;
use App\Enums\UserRole;
use App\Models\LessonEnrollment;
use App\Models\MembershipStatusRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminNotificationFeedTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_pending_requests_and_pause_deadlines_on_notifications_page(): void
    {
        $this->travelTo('2026-11-15 10:00:00');
        $admin = User::factory()->admin()->create();
        $applicant = StudentProfile::factory()->create();
        MembershipStatusRequest::factory()->for($applicant)->create([
            'type' => MembershipRequestType::Pause,
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
        ]);

        $pausedStudent = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($pausedStudent)->create(['status' => EnrollmentStatus::Paused]);
        MembershipStatusRequest::factory()->for($pausedStudent)->create([
            'type' => MembershipRequestType::Pause,
            'status' => ApplicationStatus::Approved,
            'effective_on' => '2026-10-01',
            'pause_months' => 2,
            'applied_at' => '2026-10-01 00:00:00',
            'requested_at' => '2026-09-20 10:00:00',
        ]);

        $this->actingAs($admin)
            ->get(route('staff.notifications.index'))
            ->assertOk()
            ->assertSee('通知一覧')
            ->assertSee($applicant->user->name)
            ->assertSee('休会期限が近づいています')
            ->assertSee($pausedStudent->user->name)
            ->assertSee('休会予定の終了日：2026年12月1日');
    }

    public function test_pause_deadline_beyond_thirty_days_is_not_highlighted(): void
    {
        $this->travelTo('2026-11-15 10:00:00');
        $admin = User::factory()->admin()->create();
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['status' => EnrollmentStatus::Paused]);
        MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Pause,
            'status' => ApplicationStatus::Approved,
            'effective_on' => '2026-11-01',
            'pause_months' => 2,
            'applied_at' => '2026-11-01 00:00:00',
        ]);

        $this->actingAs($admin)
            ->get(route('staff.notifications.index'))
            ->assertOk()
            ->assertDontSee($student->user->name)
            ->assertSee('現在、確認が必要な通知はありません。');
    }

    public function test_notification_page_is_forbidden_to_teachers_and_students(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->get(route('staff.notifications.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('staff.notifications.index'))
            ->assertForbidden();
    }

    public function test_admin_dashboard_shows_a_notification_summary_above_the_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $student = StudentProfile::factory()->create();
        MembershipStatusRequest::factory()->for($student)->create([
            'type' => MembershipRequestType::Pause,
            'status' => ApplicationStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('通知一覧')
            ->assertSee('申請・問い合わせ')
            ->assertSee('休会期限')
            ->assertViewHas('notificationSummary', fn (array $summary): bool => $summary['pending_requests'] === 1);
    }
}
