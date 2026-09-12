<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_user_is_redirected_from_dashboard_to_login(): void
    {
        $this->get('/student/dashboard')->assertRedirectToRoute('login');
    }

    public function test_student_can_only_open_student_dashboard(): void
    {
        $student = User::factory()->create();
        $this->actingAs($student)->getJson('/student/dashboard')->assertOk();
        $this->actingAs($student)->getJson('/staff/dashboard')->assertForbidden();
    }

    public function test_teacher_and_admin_can_open_staff_dashboard(): void
    {
        $teacher = User::factory()->teacher()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($teacher)->getJson('/staff/dashboard')->assertOk();
        $this->actingAs($admin)->getJson('/staff/dashboard')->assertOk();
    }
}
