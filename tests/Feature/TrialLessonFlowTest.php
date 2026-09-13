<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TrialLessonRequest;
use App\Models\User;
use App\Notifications\TrialLessonApplicationNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TrialLessonFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_can_view_public_trial_page_and_submit_an_application(): void
    {
        Notification::fake();
        $slot = LessonSlot::factory()->forTrials()->create(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);
        $admin = User::factory()->admin()->create();

        $this->get(route('trial-lessons.index'))->assertOk()->assertSee('はじめてのドラム体験')->assertSee($slot->teacherProfile->display_name);

        $response = $this->post(route('trial-lessons.store'), $this->validPayload($slot));

        $request = TrialLessonRequest::query()->sole();
        $response->assertRedirectToRoute('trial-lessons.complete', $request->public_reference);
        $this->assertSame(TrialLessonStatus::Pending, $request->status);
        $this->assertNotSame((string) $request->id, $request->public_reference);
        $this->assertNotNull($request->privacy_consented_at);
        $this->assertDatabaseHas('trial_lesson_request_events', ['trial_lesson_request_id' => $request->id, 'event_type' => 'submitted']);
        Notification::assertSentOnDemand(TrialLessonApplicationNotification::class);
        Notification::assertSentTo($admin, TrialLessonApplicationNotification::class);
    }

    public function test_public_form_validates_required_consent_honeypot_and_sensitive_text(): void
    {
        $slot = LessonSlot::factory()->forTrials()->create();

        $this->post(route('trial-lessons.store'), [
            ...$this->validPayload($slot),
            'privacy_accepted' => null,
            'website' => 'spam.example',
            'consultation' => 'カード番号を送ります',
        ])->assertSessionHasErrors(['privacy_accepted', 'website', 'consultation']);

        $this->assertDatabaseCount('trial_lesson_requests', 0);
    }

    public function test_public_form_is_rate_limited(): void
    {
        config()->set('musako.public_forms.trial_rate_limit_per_minute', 1);
        $slot = LessonSlot::factory()->forTrials()->create(['capacity' => 2]);

        $this->post(route('trial-lessons.store'), $this->validPayload($slot, 'first@example.test'))->assertRedirect();
        $this->post(route('trial-lessons.store'), $this->validPayload($slot, 'first@example.test'))->assertTooManyRequests();
    }

    public function test_trial_application_reserves_shared_capacity_and_rejects_duplicate_submission(): void
    {
        $slot = LessonSlot::factory()->forAllBookings()->create(['capacity' => 2]);
        ReservationRequest::factory()->for($slot)->create(['status' => ReservationStatus::Approved]);

        $this->post(route('trial-lessons.store'), $this->validPayload($slot))->assertRedirect();
        $this->post(route('trial-lessons.store'), $this->validPayload($slot, 'other@example.test'))->assertSessionHasErrors('lesson_slot_id');

        $this->assertDatabaseCount('trial_lesson_requests', 1);
    }

    public function test_approved_trial_consumes_capacity_for_regular_student_booking(): void
    {
        $slot = LessonSlot::factory()->forAllBookings()->create(['capacity' => 1]);
        TrialLessonRequest::factory()->for($slot)->approved()->create();
        $student = StudentProfile::factory()->create();

        $this->actingAs($student->user)
            ->post(route('student.reservations.store', $slot), ['student_note' => null])
            ->assertSessionHasErrors('lesson_slot');

        $this->assertDatabaseCount('reservation_requests', 0);
    }

    public function test_teacher_can_approve_and_finish_only_assigned_trial_requests(): void
    {
        Notification::fake();
        $teacher = TeacherProfile::factory()->create();
        $request = TrialLessonRequest::factory()->for(LessonSlot::factory()->for($teacher)->forTrials())->create();
        $other = TrialLessonRequest::factory()->create();

        $this->actingAs($teacher->user)->patch(route('staff.trial-lessons.update', $request), ['status' => 'approved'])->assertSessionHas('success');
        $this->actingAs($teacher->user)->patch(route('staff.trial-lessons.update', $other), ['status' => 'approved'])->assertForbidden();
        $this->actingAs($teacher->user)->patch(route('staff.trial-lessons.update', $request), ['status' => 'completed'])->assertSessionHas('success');

        $this->assertSame(TrialLessonStatus::Completed, $request->refresh()->status);
        Notification::assertSentOnDemand(TrialLessonApplicationNotification::class);
    }

    public function test_staff_can_reject_with_reason_and_applicant_can_cancel_with_valid_token_only(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $rejected = TrialLessonRequest::factory()->create();

        $this->actingAs($admin)->patch(route('staff.trial-lessons.update', $rejected), ['status' => 'rejected', 'rejection_reason' => '日程調整ができないため'])->assertSessionHas('success');
        $this->assertSame('日程調整ができないため', $rejected->refresh()->rejection_reason);

        $token = 'known-secure-token';
        $cancellable = TrialLessonRequest::factory()->create(['access_token_hash' => hash('sha256', $token)]);
        $this->delete(route('trial-lessons.cancel', [$cancellable->public_reference, 'wrong-token']))->assertNotFound();
        $this->delete(route('trial-lessons.cancel', [$cancellable->public_reference, $token]))->assertRedirect();
        $this->assertSame(TrialLessonStatus::Cancelled, $cancellable->refresh()->status);
        $this->assertNull($cancellable->active_slot_key);
        Notification::assertSentTo($admin, TrialLessonApplicationNotification::class);
    }

    public function test_students_cannot_access_trial_management_pages(): void
    {
        $request = TrialLessonRequest::factory()->create();
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('staff.trial-lessons.show', $request))->assertForbidden();
    }

    public function test_approved_trial_can_be_marked_as_no_show(): void
    {
        $admin = User::factory()->admin()->create();
        $request = TrialLessonRequest::factory()->approved()->create();

        $this->actingAs($admin)->patch(route('staff.trial-lessons.update', $request), ['status' => 'no_show'])->assertSessionHas('success');

        $this->assertSame(TrialLessonStatus::NoShow, $request->refresh()->status);
        $this->assertNotNull($request->no_show_at);
    }

    public function test_staff_dashboard_separates_pending_and_upcoming_trials(): void
    {
        $teacher = TeacherProfile::factory()->create();
        $pending = TrialLessonRequest::factory()->for(LessonSlot::factory()->for($teacher)->forTrials())->create(['name' => '未処理体験']);
        $upcoming = TrialLessonRequest::factory()->for(LessonSlot::factory()->for($teacher)->forTrials()->state([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
        ]))->approved()->create(['name' => '近日体験']);

        $this->actingAs($teacher->user)->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('体験レッスン')
            ->assertSee('新規 1件')
            ->assertSee($upcoming->name)
            ->assertDontSee($pending->name);
    }

    public function test_staff_notification_escapes_public_free_text(): void
    {
        $request = TrialLessonRequest::factory()->create(['consultation' => '<script>alert("xss")</script>']);
        $notification = new TrialLessonApplicationNotification($request, true);

        $html = $notification->toMail(User::factory()->admin()->make())->render();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @return array<string, mixed> */
    private function validPayload(LessonSlot $slot, string $email = 'trial@example.test'): array
    {
        return [
            'lesson_slot_id' => $slot->id,
            'name' => '体験 太郎',
            'name_kana' => 'タイケン タロウ',
            'email' => $email,
            'phone' => '090-1234-5678',
            'age_group' => 'adult',
            'drum_experience' => 'none',
            'consultation' => '初心者です。',
            'privacy_accepted' => '1',
            'website' => '',
        ];
    }
}
