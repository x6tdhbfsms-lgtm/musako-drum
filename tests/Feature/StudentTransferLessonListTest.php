<?php

namespace Tests\Feature;

use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StudentTransferLessonListTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_list_contains_only_own_lessons_and_links_only_available_lessons(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(10, 0));
        $student = StudentProfile::factory()->create();
        $own = ReservationRequest::factory()->create(['student_profile_id' => $student->id, 'status' => 'approved', 'lesson_slot_id' => LessonSlot::factory()->create(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHour()])->id]);
        $past = ReservationRequest::factory()->create(['student_profile_id' => $student->id, 'status' => 'approved', 'lesson_slot_id' => LessonSlot::factory()->create(['starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addHour()])->id]);
        $pending = ReservationRequest::factory()->create(['student_profile_id' => $student->id, 'status' => 'pending']);
        $other = ReservationRequest::factory()->create(['status' => 'approved']);
        $response = $this->actingAs($student->user)->get(route('student.transfer-requests.index'));
        $response->assertOk()->assertSee(route('student.transfer-requests.create', $own), false)
            ->assertSee(route('student.reservations.show', $past), false)
            ->assertSee('予約の承認待ちです。')
            ->assertDontSee(route('student.transfer-requests.create', $past), false)
            ->assertDontSee(route('student.transfer-requests.create', $pending), false)
            ->assertDontSee(route('student.reservations.show', $other), false);
        $this->assertEqualsCanonicalizing([$own->id, $past->id, $pending->id], $response->viewData('lessons')->pluck('id')->all());
    }

    public function test_all_lessons_remain_accessible_through_independent_pagination(): void
    {
        $student = StudentProfile::factory()->create();
        ReservationRequest::factory()->count(21)->create(['student_profile_id' => $student->id]);
        $response = $this->actingAs($student->user)->get(route('student.transfer-requests.index', ['lessons_page' => 2]));
        $response->assertOk();
        $this->assertSame(21, $response->viewData('lessons')->total());
        $this->assertCount(1, $response->viewData('lessons'));
        $this->assertSame(1, $response->viewData('transferRequests')->currentPage());
    }
}
