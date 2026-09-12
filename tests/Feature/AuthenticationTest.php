<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);
        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create(['password' => 'secret-password']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_teacher_login_ignores_a_student_intended_url_and_opens_the_staff_calendar(): void
    {
        $teacher = User::factory()->teacher()->create(['password' => 'secret-password']);
        $this->get(route('student.dashboard'))->assertRedirectToRoute('login');

        $this->post(route('login'), ['email' => $teacher->email, 'password' => 'secret-password'])
            ->assertRedirectToRoute('staff.dashboard');

        $this->assertAuthenticatedAs($teacher);
    }

    public function test_student_login_ignores_a_staff_intended_url_and_opens_the_student_calendar(): void
    {
        $student = User::factory()->create(['password' => 'secret-password']);
        $this->get(route('staff.dashboard'))->assertRedirectToRoute('login');

        $this->post(route('login'), ['email' => $student->email, 'password' => 'secret-password'])
            ->assertRedirectToRoute('student.dashboard');

        $this->assertAuthenticatedAs($student);
    }

    public function test_login_returns_validation_errors_for_missing_credentials(): void
    {
        $this->postJson('/login')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_returns_too_many_requests_after_five_attempts(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/login', ['email' => 'brute-force@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => 'brute-force@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }
}
