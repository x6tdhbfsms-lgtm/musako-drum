<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffStudentListTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_list_groups_students_by_course_and_hides_repeated_course_names(): void
    {
        $this->travelTo('2026-10-31 10:00:00');
        $admin = User::factory()->admin()->create();
        $currentStudent = StudentProfile::factory()->for(User::factory()->state(['name' => '現在契約中の生徒']), 'user')->create();
        $currentCourse = Course::factory()->create(['name' => 'studio34（60分）']);
        LessonEnrollment::factory()->for($currentStudent)->for($currentCourse)->create([
            'status' => EnrollmentStatus::Active,
            'starts_on' => '2026-10-01',
            'weekday' => 5,
            'regular_week_numbers' => [1, 3],
            'monthly_lesson_limit' => 2,
        ]);

        $futureStudent = StudentProfile::factory()->for(User::factory()->state(['name' => '開始予定の生徒']), 'user')->create();
        $futureCourse = Course::factory()->create(['name' => '電子ドラムコース（45分）']);
        LessonEnrollment::factory()->for($futureStudent)->for($futureCourse)->create([
            'status' => EnrollmentStatus::Active,
            'starts_on' => '2026-11-01',
            'weekday' => 2,
            'monthly_lesson_limit' => 4,
        ]);
        $studentWithoutEnrollment = StudentProfile::factory()->for(User::factory()->state(['name' => '契約情報のない生徒']), 'user')->create();

        $response = $this->actingAs($admin)->get(route('staff.students.index'));

        $response->assertSeeText('現在契約中の生徒')
            ->assertSeeText('電子ドラムコース')
            ->assertSeeText('studio34')
            ->assertSeeText('第1・第3金曜日')
            ->assertSeeText('11月1日開始 · 第1・第2・第3・第4火曜日')
            ->assertDontSeeText('電子ドラムコース（45分）')
            ->assertDontSeeText('studio34（60分）')
            ->assertSeeText('契約情報のない生徒')
            ->assertSeeText('現在有効な契約情報はありません。');
    }
}
