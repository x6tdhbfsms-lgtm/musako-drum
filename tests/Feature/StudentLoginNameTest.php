<?php

namespace Tests\Feature;

use App\Actions\UpdateStaffStudent;
use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Models\AdmissionApplication;
use App\Models\Course;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\AdmissionApplicationNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class StudentLoginNameTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_logs_in_by_normalized_login_name_but_not_email_or_display_name(): void
    {
        $user = User::factory()->create(['name' => '同姓同名', 'login_name' => 'taro01']);
        User::factory()->create(['name' => '同姓同名']);
        $this->postJson('/login', ['identifier' => ' TARO01 ', 'password' => 'password'])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        foreach ([$user->email, $user->name] as $identifier) {
            $this->postJson('/login', ['identifier' => $identifier, 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('identifier');
            $this->assertGuest();
        }
    }

    public function test_suspended_student_cannot_login_and_staff_keeps_email_login(): void
    {
        $student = User::factory()->suspended()->create();
        $this->postJson('/login', ['identifier' => $student->login_name, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
        $admin = User::factory()->admin()->create();
        $this->postJson('/login', ['identifier' => $admin->email, 'password' => 'password'])->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_registers_student_with_automatically_generated_login_name_and_chosen_password(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->create(['login_name' => 'student001']);
        $course = Course::factory()->create(['code' => 'INDIVIDUAL-60', 'default_lesson_minutes' => 60]);
        $venue = Venue::factory()->create(['name' => 'studio34', 'code' => 'MUSAKO']);
        $teacher = TeacherProfile::factory()->create();
        $data = [
            'name' => '登録テスト',
            'password' => 'Trial-Password-2026', 'password_confirmation' => 'Trial-Password-2026',
            'course_id' => $course->id, 'venue_id' => $venue->id, 'teacher_profile_id' => $teacher->id,
            'lesson_type' => 'flex', 'pricing_category' => 'standard', 'monthly_lesson_count' => 2,
            'lesson_minutes' => 60, 'preferred_start_date' => today()->toDateString(), 'privacy_accepted' => 1,
        ];
        $this->actingAs($admin)->get(route('staff.admission-applications.create'))->assertOk()->assertSeeText('student002');
        $this->actingAs($admin)->post(route('staff.admission-applications.store'), $data)->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'student002'));
        $application = AdmissionApplication::sole();
        $student = $application->convertedUser;
        $this->assertSame('student002', $student->login_name);
        $this->assertNull($student->email);
        $this->assertTrue(Hash::check($data['password'], $student->password));
        $this->assertNull($application->initial_password_hash);
        $this->assertNull($application->pending_login_name);
        $this->assertSame(UserRole::Student, $student->role);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNotSentTo($student, AdmissionApplicationNotification::class);
        $this->assertDatabaseCount('student_profiles', 1);
        $this->post('/logout');
        $this->postJson('/login', ['identifier' => $student->login_name, 'password' => $data['password']])->assertOk();
    }

    public function test_admin_registration_skips_existing_and_pending_student_login_names(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        User::factory()->create(['login_name' => 'student001']);
        AdmissionApplication::factory()->create(['login_name' => 'student002', 'pending_login_name' => 'student002']);

        $this->actingAs($admin)->get(route('staff.admission-applications.create'))->assertOk()->assertSeeText('student003');
    }

    public function test_admin_resets_password_without_putting_it_in_audit_or_old_input(): void
    {
        $student = StudentProfile::factory()->create();
        $admin = User::factory()->admin()->create();
        $data = ['name' => $student->user->name, 'login_name' => $student->user->login_name,
            'revision' => app(UpdateStaffStudent::class)->revision($student),
            'password' => 'Replacement-2026', 'password_confirmation' => 'Replacement-2026'];
        $this->actingAs($admin)->patch(route('staff.students.update', $student), $data)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check($data['password'], $student->user->fresh()->password));
        $audit = $student->personalInformationChangeRequests()->sole();
        $this->assertArrayNotHasKey('password', $audit->after_values);
        $this->patch(route('staff.students.update', $student), [...$data, 'password_confirmation' => 'wrong'])->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
    }

    public function test_teacher_cannot_reset_student_password(): void
    {
        $student = StudentProfile::factory()->create();
        $hash = $student->user->password;
        $this->actingAs(User::factory()->teacher()->create())->patch(route('staff.students.update', $student), [
            'name' => $student->user->name, 'login_name' => $student->user->login_name,
            'revision' => app(UpdateStaffStudent::class)->revision($student),
            'password' => 'Replacement-2026', 'password_confirmation' => 'Replacement-2026',
        ])->assertForbidden();
        $this->assertSame($hash, $student->user->fresh()->password);
    }

    public function test_old_email_password_reset_link_is_rejected_for_student(): void
    {
        $student = User::factory()->create();
        $hash = $student->password;
        $token = Password::broker()->createToken($student);
        $this->post(route('password.update'), ['email' => $student->email, 'token' => $token,
            'password' => 'Replacement-2026', 'password_confirmation' => 'Replacement-2026'])->assertForbidden();
        $this->assertSame($hash, $student->fresh()->password);
    }

    public function test_multibyte_password_over_bcrypt_limit_returns_validation_error(): void
    {
        $student = StudentProfile::factory()->create();
        $password = str_repeat('あ', 25);
        $this->actingAs(User::factory()->admin()->create())->patch(route('staff.students.update', $student), [
            'name' => $student->user->name, 'login_name' => $student->user->login_name,
            'revision' => app(UpdateStaffStudent::class)->revision($student),
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
    }

    public function test_student_email_notifications_are_disabled_even_with_legacy_email(): void
    {
        $student = User::factory()->create();
        foreach (NotificationCategory::cases() as $category) {
            $this->assertFalse($student->canReceiveEmailNotification($category));
        }
    }
}
