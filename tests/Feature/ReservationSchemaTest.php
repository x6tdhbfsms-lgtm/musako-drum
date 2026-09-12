<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\Venue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReservationSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reservation_request_keeps_slot_contract_and_review_information(): void
    {
        $this->travelTo('2026-09-12 12:00:00');
        $student = StudentProfile::factory()->create();
        $teacher = TeacherProfile::factory()->create();
        $course = Course::factory()->create();
        $venue = Venue::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->for($course)->create(['monthly_lesson_limit' => 4, 'starts_on' => '2026-09-01']);
        $slot = LessonSlot::factory()->for($teacher)->for($venue)->for($course)->create(['starts_at' => '2026-09-20 10:00:00', 'ends_at' => '2026-09-20 11:00:00']);
        $reservation = ReservationRequest::create(['student_profile_id' => $student->id, 'lesson_slot_id' => $slot->id, 'lesson_enrollment_id' => $enrollment->id, 'requested_at' => now(), 'status' => ReservationStatus::Pending]);
        $this->assertTrue($reservation->studentProfile->is($student));
        $this->assertTrue($reservation->lessonSlot->is($slot));
        $this->assertTrue($reservation->lessonEnrollment->is($enrollment));
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
    }
}
