<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PortalAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_both_portals_remain_authenticated_in_either_login_order(bool $studentFirst): void
    {
        $teacher = TeacherProfile::factory()->for(User::factory()->teacher()->create(['password' => 'portal-password']))->create();
        $student = StudentProfile::factory()->for(User::factory()->create(['password' => 'portal-password']))->create();
        $users = $studentFirst ? [$student->user, $teacher->user] : [$teacher->user, $student->user];
        foreach ($users as $user) {
            $this->post('/login', ['identifier' => $user->role->value === 'student' ? $user->login_name : $user->email, 'password' => 'portal-password'])
                ->assertSessionHasNoErrors();
        }
        $this->get(route('staff.dashboard'))->assertOk()->assertViewHas('teacherFilterId', $teacher->id);
        $this->get(route('student.dashboard'))->assertOk()->assertSee($student->user->name);
        $this->assertAuthenticatedAs($teacher->user, 'staff');
        $this->assertAuthenticatedAs($student->user, 'student');
        $this->get(route('login'))->assertOk()->assertSee('別々のタブ');
    }

    #[TestWith(['staff'])]
    #[TestWith(['student'])]
    public function test_logout_only_ends_the_selected_portal_and_preserves_other_forms(string $portal): void
    {
        $teacher = User::factory()->teacher()->create(['password' => 'portal-password', 'login_name' => null]);
        $student = User::factory()->create(['password' => 'portal-password']);
        foreach ([$teacher, $student] as $user) {
            $this->post('/login', ['identifier' => $user->role->value === 'student' ? $user->login_name : $user->email, 'password' => 'portal-password'])
                ->assertSessionHasNoErrors();
        }
        $token = session()->token();
        $other = $portal === 'staff' ? 'student' : 'staff';
        $this->post('/logout', ['portal' => $portal])->assertRedirect('/login');
        $this->assertGuest($portal);
        $this->assertAuthenticated($other);
        $this->assertSame($token, session()->token());
    }

    public function test_second_login_keeps_the_other_portals_csrf_token(): void
    {
        $teacher = User::factory()->teacher()->create(['password' => 'portal-password']);
        $student = User::factory()->create(['password' => 'portal-password']);
        $this->post('/login', ['identifier' => $teacher->email, 'password' => 'portal-password']);
        $token = session()->token();
        $this->post('/login', ['identifier' => $student->login_name, 'password' => 'portal-password']);
        $this->assertSame($token, session()->token());
    }

    public function test_password_change_invalidates_only_affected_portal_even_when_session_is_shared(): void
    {
        $teacher = User::factory()->teacher()->create(['password' => 'portal-password']);
        $student = StudentProfile::factory()->for(User::factory()->create(['password' => 'portal-password']))->create();
        $this->post('/login', ['identifier' => $teacher->email, 'password' => 'portal-password']);
        $this->post('/login', ['identifier' => $student->user->login_name, 'password' => 'portal-password']);
        $student->user->update(['password' => Hash::make('changed-password')]);
        Auth::forgetGuards();
        $this->get(route('student.dashboard'))->assertRedirectToRoute('login');
        $this->assertAuthenticatedAs($teacher, 'staff');
    }

    public function test_student_login_cannot_gain_staff_access_by_changing_portal_parameter(): void
    {
        $student = User::factory()->create(['password' => 'portal-password']);
        $this->post('/login', ['identifier' => $student->login_name, 'password' => 'portal-password', 'portal' => 'staff']);
        $this->get(route('staff.dashboard'))->assertForbidden();
        $this->assertGuest('staff');
    }
}
