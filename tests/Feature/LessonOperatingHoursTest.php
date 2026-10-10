<?php

namespace Tests\Feature;

use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\TeacherProfile;
use App\Models\Venue;
use App\Services\LessonOperatingHours;
use App\Services\TransferCalendarAvailability;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LessonOperatingHoursTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['2026-10-12 11:45', '2026-10-12 12:30', false])]
    #[TestWith(['2026-10-12 12:00', '2026-10-12 12:45', true])]
    #[TestWith(['2026-10-12 20:15', '2026-10-12 21:00', true])]
    #[TestWith(['2026-10-12 20:30', '2026-10-12 21:15', false])]
    #[TestWith(['2026-10-10 09:45', '2026-10-10 10:30', false])]
    #[TestWith(['2026-10-10 10:00', '2026-10-10 10:45', true])]
    #[TestWith(['2026-10-11 19:15', '2026-10-11 20:00', true])]
    #[TestWith(['2026-10-11 19:30', '2026-10-11 20:15', false])]
    public function test_staff_cannot_create_outside_operating_hours(string $start, string $end, bool $allowed): void
    {
        $this->travelTo('2026-10-09 12:00');
        $teacher = TeacherProfile::factory()->create();
        $response = $this->actingAs($teacher->user)->post(route('staff.lesson-slots.store'), [
            'starts_at' => $start, 'ends_at' => $end, 'capacity' => 1,
            'venue_id' => Venue::factory()->create(['lesson_room_type' => 'music_room'])->id,
        ]);
        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertDatabaseCount('lesson_slots', 1);
        } else {
            $response->assertSessionHasErrors('starts_at');
            $this->assertDatabaseCount('lesson_slots', 0);
        }
    }

    public function test_transfer_candidates_fit_each_days_hours_and_lesson_duration(): void
    {
        $this->travelTo('2026-10-09 12:00');
        $slot = LessonSlot::factory()->for(Venue::factory()->create(['lesson_room_type' => 'music_room']))->create([
            'starts_at' => '2026-10-20 12:00', 'ends_at' => '2026-10-20 12:45',
        ]);
        $original = ReservationRequest::factory()->for($slot)->create(['status' => 'approved']);
        $slots = app(TransferCalendarAvailability::class)->slots($original);
        $this->assertTrue($slots->every(fn ($slot) => LessonOperatingHours::allows($slot->starts_at, $slot->ends_at)));
        foreach (['2026-10-10 10:00', '2026-10-11 19:15', '2026-10-12 12:00', '2026-10-12 20:15'] as $time) {
            $this->assertTrue($slots->contains(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === $time), $time);
        }
        foreach (['2026-10-10 19:30', '2026-10-12 11:45', '2026-10-12 20:30'] as $time) {
            $this->assertFalse($slots->contains(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === $time), $time);
        }
    }

    #[TestWith(['2026-10-10', 10, 19])]
    #[TestWith(['2026-10-12', 12, 20])]
    public function test_day_calendar_has_requested_hour_range(string $date, int $first, int $last): void
    {
        $this->actingAs(TeacherProfile::factory()->create()->user)->get(route('staff.dashboard', ['view' => 'day', 'date' => $date]))
            ->assertOk()->assertViewHas('timelineHours', range($first, $last));
    }
}
