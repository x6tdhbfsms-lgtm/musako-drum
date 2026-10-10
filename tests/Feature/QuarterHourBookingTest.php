<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use App\Models\Venue;
use App\Services\TeacherBookingAvailability;
use App\Services\TransferCalendarAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class QuarterHourBookingTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['12:15', '13:00', '13:30', true])]
    #[TestWith(['12:30', '13:15', '13:30', true])]
    #[TestWith(['12:15', '13:00', '13:00', false])]
    #[TestWith(['12:30', '13:15', '13:00', false])]
    #[TestWith(['12:30', '13:15', '13:15', false])]
    public function test_staff_creation_applies_travel_and_studio_start_times(string $start, string $end, string $target, bool $allowed): void
    {
        $original = $this->electronic($start, $end);
        $studio = Venue::factory()->create(['lesson_room_type' => 'studio34']);
        $before = LessonSlot::count();
        $response = $this->actingAs($original->lessonSlot->teacherProfile->user)->post(route('staff.lesson-slots.store'), [
            'venue_id' => $studio->id, 'starts_at' => '2026-11-10 '.$target,
            'ends_at' => CarbonImmutable::parse('2026-11-10 '.$target)->addHour()->format('Y-m-d H:i'), 'capacity' => 1, 'booking_audience' => 'regular',
        ]);
        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($before + 1, LessonSlot::count());
        } else {
            $response->assertSessionHasErrors('starts_at');
            $this->assertSame($before, LessonSlot::count());
        }
    }

    #[TestWith([30, '13:00', '13:30'])]
    #[TestWith([45, '13:00', '13:45'])]
    #[TestWith([30, '13:15', '13:45'])]
    #[TestWith([45, '13:15', '14:00'])]
    public function test_electronic_transfer_offers_and_accepts_quarter_hour_back_to_back_lessons(int $minutes, string $start, string $end): void
    {
        Notification::fake();
        $busy = $this->electronic($start === '13:00' ? '12:15' : '12:30', $start);
        $originalSlot = LessonSlot::factory()->create([
            'teacher_profile_id' => $busy->lessonSlot->teacher_profile_id, 'venue_id' => $busy->lessonSlot->venue_id,
            'starts_at' => '2026-11-20 10:00', 'ends_at' => '2026-11-20 10:'.$minutes,
        ]);
        $original = ReservationRequest::factory()->for($originalSlot)->create([
            'status' => ReservationStatus::Approved, 'studio_fee_amount' => 1000, 'studio_fee_priced_on' => '2026-11-20',
        ]);
        $slots = app(TransferCalendarAvailability::class)->slots($original);
        $this->assertTrue($slots->contains(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === '2026-11-10 '.$start && $slot->ends_at->format('H:i') === $end));
        $this->assertTrue($slots->contains(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === '2026-11-11 12:15'));
        $this->assertTrue($slots->contains(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === '2026-11-11 12:45'));
        $this->actingAs($original->studentProfile->user)->post(route('student.transfer-requests.store', $original), [
            'requested_starts_at' => '2026-11-10 '.$start,
        ])->assertSessionHasNoErrors();
        $transfer = TransferRequest::sole();
        $this->actingAs($originalSlot->teacherProfile->user)->patch(route('staff.transfer-requests.update', $transfer), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame($end, $transfer->fresh()->resultingReservationRequest->lessonSlot->ends_at->format('H:i'));
    }

    public function test_studio_transfer_and_new_student_booking_accept_fifteen_minutes_but_not_overlap(): void
    {
        Notification::fake();
        $busy = $this->electronic('12:30', '13:15');
        $studio = Venue::factory()->create(['lesson_room_type' => 'studio34']);
        $originalSlot = LessonSlot::factory()->create([
            'teacher_profile_id' => $busy->lessonSlot->teacher_profile_id, 'venue_id' => $studio->id,
            'starts_at' => '2026-11-20 10:00', 'ends_at' => '2026-11-20 11:00',
        ]);
        $original = ReservationRequest::factory()->for($originalSlot)->create(['status' => ReservationStatus::Approved]);
        $slots = app(TransferCalendarAvailability::class)->slots($original);
        $this->assertTrue($slots->contains(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === '2026-11-10 13:30'));
        $this->assertFalse($slots->contains(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === '2026-11-10 13:00'));
        $this->assertTrue($slots->every(fn ($slot) => in_array($slot->starts_at->minute, [0, 30], true)));
        $target = LessonSlot::factory()->create([
            'teacher_profile_id' => $busy->lessonSlot->teacher_profile_id, 'venue_id' => $studio->id,
            'starts_at' => '2026-11-10 13:30', 'ends_at' => '2026-11-10 14:30',
        ]);
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['course_id' => $target->course_id]);
        $this->actingAs($student->user)->post(route('student.reservations.store', $target))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('reservation_requests', ['student_profile_id' => $student->id, 'lesson_slot_id' => $target->id, 'status' => 'pending']);
    }

    #[TestWith(['14:00', false])]
    #[TestWith(['14:10', false])]
    #[TestWith(['14:15', true])]
    public function test_reverse_direction_requires_at_least_fifteen_minutes(string $start, bool $allowed): void
    {
        $electronic = $this->electronic('12:15', '13:00');
        $studio = LessonSlot::factory()->create([
            'teacher_profile_id' => $electronic->lessonSlot->teacher_profile_id,
            'venue_id' => Venue::factory()->create(['lesson_room_type' => 'studio34'])->id,
            'starts_at' => '2026-11-10 13:00', 'ends_at' => '2026-11-10 14:00',
        ]);
        ReservationRequest::factory()->for($studio)->create(['status' => ReservationStatus::Approved]);
        $target = LessonSlot::factory()->create([
            'teacher_profile_id' => $studio->teacher_profile_id, 'venue_id' => $electronic->lessonSlot->venue_id,
            'starts_at' => '2026-11-10 '.$start, 'ends_at' => '2026-11-10 15:00',
        ]);
        $this->assertSame($allowed, app(TeacherBookingAvailability::class)->availableSlots(collect([$target]))->isNotEmpty());
    }

    private function electronic(string $start, string $end): ReservationRequest
    {
        $this->travelTo('2026-11-01 10:00');
        $slot = LessonSlot::factory()->for(Venue::factory()->create(['lesson_room_type' => 'music_room']))->create([
            'starts_at' => '2026-11-10 '.$start, 'ends_at' => '2026-11-10 '.$end,
        ]);

        return ReservationRequest::factory()->for($slot)->create(['status' => ReservationStatus::Approved]);
    }
}
