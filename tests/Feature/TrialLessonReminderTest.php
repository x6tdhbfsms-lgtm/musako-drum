<?php

namespace Tests\Feature;

use App\Enums\TrialLessonStatus;
use App\Jobs\SendTrialLessonReminder;
use App\Models\LessonSlot;
use App\Models\TrialLessonReminderDelivery;
use App\Models\TrialLessonRequest;
use App\Notifications\TrialLessonReminderNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrialLessonReminderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_queues_approved_trial_once_for_target_date(): void
    {
        Queue::fake([SendTrialLessonReminder::class]);
        $slot = LessonSlot::factory()->forTrials()->create(['starts_at' => '2026-09-20 14:00:00', 'ends_at' => '2026-09-20 15:00:00']);
        TrialLessonRequest::factory()->for($slot)->approved()->create();
        TrialLessonRequest::factory()->for($slot)->create(['status' => TrialLessonStatus::Rejected, 'active_slot_key' => null]);

        $this->artisan('lesson-reminders:send', ['--date' => '2026-09-20'])->assertSuccessful();
        $this->artisan('lesson-reminders:send', ['--date' => '2026-09-20'])->assertSuccessful();

        $this->assertDatabaseCount('trial_lesson_reminder_deliveries', 1);
        Queue::assertPushed(SendTrialLessonReminder::class, 1);
    }

    public function test_trial_reminder_job_sends_email_once_and_records_delivery(): void
    {
        Notification::fake();
        $request = TrialLessonRequest::factory()->approved()->create();
        $delivery = TrialLessonReminderDelivery::factory()->for($request)->create();

        $job = new SendTrialLessonReminder($delivery->id);
        $job->handle();
        $job->handle();

        $this->assertNotNull($delivery->refresh()->sent_at);
        $this->assertSame(1, $delivery->attempts);
        Notification::assertSentOnDemand(TrialLessonReminderNotification::class);
    }
}
