<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AttendanceNoticeType;
use App\Enums\LessonType;
use App\Enums\MembershipRequestType;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\AttendanceNotice;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\MembershipStatusRequest;
use App\Models\ReservationRequest;
use App\Models\User;
use App\Services\FlexInactivityWarning;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FlexInactivityWarningTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['2026-10-16', false])]
    #[TestWith(['2026-11-16', false])]
    #[TestWith(['2026-12-15 23:59:59', false])]
    #[TestWith(['2026-12-16', true])]
    public function test_warning_begins_on_the_two_month_anniversary(string $date, bool $expected): void
    {
        $this->travelTo(Carbon::parse($date, 'Asia/Tokyo'));
        $enrollment = LessonEnrollment::factory()->create(['lesson_type' => LessonType::Flex, 'starts_on' => '2026-10-16']);
        $this->assertSame($expected, app(FlexInactivityWarning::class)->appliesTo($enrollment->studentProfile));
    }

    #[TestWith(['approved', '2026-11-20', false])]
    #[TestWith(['pending', '2026-11-20', true])]
    #[TestWith(['approved', '2026-12-20', true])]
    #[TestWith(['cancelled', '2026-11-20', true])]
    public function test_only_completed_lessons_extend_the_baseline(string $status, string $date, bool $expected): void
    {
        $this->travelTo(Carbon::parse('2026-12-16', 'Asia/Tokyo'));
        $enrollment = LessonEnrollment::factory()->create(['lesson_type' => LessonType::Flex, 'starts_on' => '2026-10-16']);
        $slot = LessonSlot::factory()->create(['course_id' => $enrollment->course_id, 'starts_at' => "$date 13:00", 'ends_at' => "$date 14:00"]);
        ReservationRequest::factory()->create(['student_profile_id' => $enrollment->student_profile_id, 'lesson_enrollment_id' => $enrollment->id, 'lesson_slot_id' => $slot->id, 'status' => ReservationStatus::from($status)]);
        $this->assertSame($expected, app(FlexInactivityWarning::class)->appliesTo($enrollment->studentProfile));
    }

    #[TestWith(['regular', false])]
    #[TestWith(['flex', true])]
    public function test_contract_versions_preserve_only_continuous_flex_usage(string $previousType, bool $expected): void
    {
        $this->travelTo(Carbon::parse('2026-12-16', 'Asia/Tokyo'));
        $parent = LessonEnrollment::factory()->create(['lesson_type' => LessonType::from($previousType), 'starts_on' => '2026-01-01', 'ends_on' => '2026-11-30']);
        LessonEnrollment::factory()->create(['student_profile_id' => $parent->student_profile_id, 'lesson_type' => LessonType::Flex, 'starts_on' => '2026-12-01', 'supersedes_lesson_enrollment_id' => $parent->id, 'monthly_lesson_limit' => 2]);
        $this->assertSame($expected, app(FlexInactivityWarning::class)->appliesTo($parent->studentProfile));
    }

    public function test_returning_to_flex_after_regular_starts_a_new_period(): void
    {
        $this->travelTo(Carbon::parse('2026-12-16', 'Asia/Tokyo'));
        $first = LessonEnrollment::factory()->create(['lesson_type' => LessonType::Flex, 'starts_on' => '2026-01-01', 'ends_on' => '2026-10-31']);
        $regular = LessonEnrollment::factory()->create(['student_profile_id' => $first->student_profile_id, 'lesson_type' => LessonType::Regular, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'supersedes_lesson_enrollment_id' => $first->id]);
        LessonEnrollment::factory()->create(['student_profile_id' => $first->student_profile_id, 'lesson_type' => LessonType::Flex, 'starts_on' => '2026-12-01', 'supersedes_lesson_enrollment_id' => $regular->id]);
        $this->assertFalse(app(FlexInactivityWarning::class)->appliesTo($first->studentProfile));
    }

    public function test_applied_resumption_starts_a_new_period(): void
    {
        $this->travelTo(Carbon::parse('2026-12-16', 'Asia/Tokyo'));
        $enrollment = LessonEnrollment::factory()->create(['lesson_type' => LessonType::Flex, 'starts_on' => '2026-01-01']);
        MembershipStatusRequest::factory()->create(['student_profile_id' => $enrollment->student_profile_id, 'type' => MembershipRequestType::Resume, 'status' => ApplicationStatus::Approved, 'effective_on' => '2026-12-01', 'applied_at' => '2026-12-01', 'previous_enrollment_statuses' => [$enrollment->id => 'paused']]);
        $this->assertFalse(app(FlexInactivityWarning::class)->appliesTo($enrollment->studentProfile));
        $this->travelTo(Carbon::parse('2027-02-01', 'Asia/Tokyo'));
        $this->assertTrue(app(FlexInactivityWarning::class)->appliesTo($enrollment->studentProfile));
    }

    public function test_legacy_unlinked_lesson_is_matched_without_writing_data(): void
    {
        $this->travelTo(Carbon::parse('2026-12-16', 'Asia/Tokyo'));
        $enrollment = LessonEnrollment::factory()->create(['lesson_type' => LessonType::Flex, 'starts_on' => '2026-01-01']);
        $slot = LessonSlot::factory()->create(['course_id' => $enrollment->course_id, 'starts_at' => '2026-11-01 13:00', 'ends_at' => '2026-11-01 14:00']);
        $reservation = ReservationRequest::factory()->create(['student_profile_id' => $enrollment->student_profile_id, 'lesson_enrollment_id' => null, 'lesson_slot_id' => $slot->id, 'status' => ReservationStatus::Approved]);
        $this->assertFalse(app(FlexInactivityWarning::class)->appliesTo($enrollment->studentProfile));
        $this->assertNull($reservation->fresh()->lesson_enrollment_id);
    }

    public function test_new_flex_student_has_no_warning_on_staff_page(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16', 'Asia/Tokyo'));
        $enrollment = LessonEnrollment::factory()->create(['lesson_type' => LessonType::Flex, 'starts_on' => '2026-10-16']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('staff.students.show', $enrollment->student_profile_id))
            ->assertOk()->assertViewHas('hasInactiveFlexWarning', false);
    }

    public function test_absence_is_not_an_actual_lesson(): void
    {
        $this->travelTo(Carbon::parse('2026-12-16', 'Asia/Tokyo'));
        $enrollment = LessonEnrollment::factory()->create(['lesson_type' => LessonType::Flex, 'starts_on' => '2026-01-01']);
        $slot = LessonSlot::factory()->create(['course_id' => $enrollment->course_id, 'starts_at' => '2026-11-01 13:00', 'ends_at' => '2026-11-01 14:00']);
        $reservation = ReservationRequest::factory()->create(['student_profile_id' => $enrollment->student_profile_id, 'lesson_enrollment_id' => $enrollment->id, 'lesson_slot_id' => $slot->id, 'status' => ReservationStatus::Approved]);
        AttendanceNotice::factory()->create(['reservation_request_id' => $reservation->id, 'type' => AttendanceNoticeType::Absence]);
        $this->assertTrue(app(FlexInactivityWarning::class)->appliesTo($enrollment->studentProfile));
    }
}
