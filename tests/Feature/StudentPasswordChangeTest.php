<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentPasswordChangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_home_shows_their_login_name(): void
    {
        $student = StudentProfile::factory()->create();

        $this->actingAs($student->user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('ログイン名：')
            ->assertSee($student->user->login_name);
    }

    public function test_student_can_change_password_from_personal_information_page(): void
    {
        $student = StudentProfile::factory()->for(
            User::factory()->create(['password' => 'Current-password-2026']),
            'user',
        )->create();

        $this->actingAs($student->user)
            ->get(route('student.personal-information-change-requests.index'))
            ->assertOk()
            ->assertSeeText('パスワード変更');

        $this->put(route('student.personal-information-change-requests.password'), [
            'current_password' => 'Current-password-2026',
            'password' => 'New-password-2026',
            'password_confirmation' => 'New-password-2026',
        ])->assertRedirect(route('student.personal-information-change-requests.index'))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('New-password-2026', $student->user->fresh()->password));
        $this->assertAuthenticatedAs($student->user);
        $this->post('/logout');
        $this->postJson('/login', ['identifier' => $student->user->login_name, 'password' => 'New-password-2026'])->assertOk();
    }

    public function test_password_change_requires_current_password_and_matching_new_password(): void
    {
        $student = StudentProfile::factory()->create();
        $originalHash = $student->user->password;

        $this->actingAs($student->user)->put(route('student.personal-information-change-requests.password'), [
            'current_password' => 'incorrect',
            'password' => 'New-password-2026',
            'password_confirmation' => 'New-password-2026',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('student.personal-information-change-requests.password'), [
            'current_password' => 'password',
            'password' => 'New-password-2026',
            'password_confirmation' => 'Does-not-match-2026',
        ])->assertSessionHasErrors('password');

        $this->assertSame($originalHash, $student->user->fresh()->password);
    }
}
