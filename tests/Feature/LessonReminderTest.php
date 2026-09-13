<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\SendLessonReminder;
use App\Models\LessonReminderDelivery;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Notifications\LessonReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LessonReminderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_queues_only_tomorrows_approved_reservations(): void
    {
        $this->travelTo('2026-09-14 18:00:00');
        $approved = $this->reservation('2026-09-15 18:00:00', ReservationStatus::Approved);
        $this->reservation('2026-09-15 19:00:00', ReservationStatus::Pending);
        $this->reservation('2026-09-16 18:00:00', ReservationStatus::Approved);
        Queue::fake([SendLessonReminder::class]);

        $this->artisan('lesson-reminders:send')
            ->expectsOutput('2026-09-15 の前日リマインダーを 1件キューへ登録しました。')
            ->assertSuccessful();

        $this->assertDatabaseHas('lesson_reminder_deliveries', [
            'reservation_request_id' => $approved->id,
            'user_id' => $approved->studentProfile->user_id,
        ]);
        $this->assertSame('2026-09-15', $approved->lessonReminderDelivery->lesson_on->toDateString());
        Queue::assertPushed(SendLessonReminder::class, fn (SendLessonReminder $job): bool => $job->lessonReminderDeliveryId === $approved->lessonReminderDelivery->id);
    }

    public function test_command_does_not_queue_the_same_reminder_twice(): void
    {
        $this->travelTo('2026-09-14 18:00:00');
        $reservation = $this->reservation('2026-09-15 18:00:00', ReservationStatus::Approved);
        Queue::fake([SendLessonReminder::class]);

        $this->artisan('lesson-reminders:send')->assertSuccessful();
        $this->artisan('lesson-reminders:send')->expectsOutput('2026-09-15 の前日リマインダーを 0件キューへ登録しました。')->assertSuccessful();

        $this->assertDatabaseCount('lesson_reminder_deliveries', 1);
        Queue::assertPushed(SendLessonReminder::class, 1);
        $this->assertSame($reservation->id, LessonReminderDelivery::query()->sole()->reservation_request_id);
    }

    public function test_command_skips_a_student_without_email_or_with_reminders_disabled(): void
    {
        $this->travelTo('2026-09-14 18:00:00');
        $missingEmail = $this->reservation('2026-09-15 18:00:00', ReservationStatus::Approved);
        $missingEmail->studentProfile->user->update(['email' => null]);
        $disabled = $this->reservation('2026-09-15 19:00:00', ReservationStatus::Approved);
        $disabled->studentProfile->user->update(['notification_preferences' => ['reminder' => false]]);
        Queue::fake([SendLessonReminder::class]);

        $this->artisan('lesson-reminders:send')->expectsOutput('2026-09-15 の前日リマインダーを 0件キューへ登録しました。')->assertSuccessful();

        $this->assertDatabaseCount('lesson_reminder_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_sends_the_reminder_once_and_records_delivery(): void
    {
        $this->travelTo('2026-09-14 18:00:00');
        $reservation = $this->reservation('2026-09-15 18:00:00', ReservationStatus::Approved);
        $delivery = LessonReminderDelivery::factory()->for($reservation)->for($reservation->studentProfile->user)->create([
            'lesson_on' => '2026-09-15',
        ]);
        Notification::fake();

        $job = new SendLessonReminder($delivery->id);
        $job->handle();
        $job->handle();

        Notification::assertSentToTimes($reservation->studentProfile->user, LessonReminderNotification::class, 1);
        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_reminder_email_contains_lesson_details_and_no_internal_identifier(): void
    {
        $reservation = $this->reservation('2026-09-15 18:00:00', ReservationStatus::Approved);
        $reservation->lessonSlot->teacherProfile->update(['display_name' => '武蔵先生']);
        $reservation->lessonSlot->venue->update(['name' => '武蔵小金井']);
        $reservation->lessonSlot->course->update(['name' => '個人レッスン']);

        $html = (new LessonReminderNotification($reservation))->toMail($reservation->studentProfile->user)->render();

        $this->assertStringContainsString('明日のレッスンのお知らせ', $html);
        $this->assertStringContainsString('2026年9月15日 18:00', $html);
        $this->assertStringContainsString('武蔵先生', $html);
        $this->assertStringContainsString('武蔵小金井', $html);
        $this->assertStringContainsString('個人レッスン', $html);
        $this->assertStringNotContainsString('reservation_request_id', $html);
    }

    private function reservation(
        string $startsAt,
        ReservationStatus $status,
        ?TeacherProfile $teacher = null,
        ?string $studentName = null,
    ): ReservationRequest {
        $teacher ??= TeacherProfile::factory()->create();
        $student = StudentProfile::factory()->create();
        if ($studentName !== null) {
            $student->user->update(['name' => $studentName]);
        }
        $starts = CarbonImmutable::parse($startsAt, config('app.timezone'));
        $slot = LessonSlot::factory()->for($teacher)->create([
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
        ]);

        return ReservationRequest::factory()->for($student)->for($slot)->create(['status' => $status]);
    }
}
