<?php

namespace Tests\Feature;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Enums\TrialLessonStatus;
use App\Models\AdmissionApplication;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\TrialLessonRequest;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\AdmissionApplicationNotification;
use App\Notifications\InitialPasswordSetupNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdmissionApplicationFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_completed_trial_can_open_and_submit_admission_application(): void
    {
        Notification::fake();
        $token = 'admission-access-token';
        $trial = TrialLessonRequest::factory()->create([
            'status' => TrialLessonStatus::Completed,
            'completed_at' => now(),
            'active_slot_key' => null,
            'access_token_hash' => hash('sha256', $token),
        ]);

        $this->get(route('admissions.create', [$trial->public_reference, 'wrong']))->assertNotFound();
        $this->get(route('admissions.create', [$trial->public_reference, $token]))->assertOk()->assertSee('入会申込み');

        $response = $this->post(route('admissions.store', [$trial->public_reference, $token]), $this->validPayload($trial));

        $application = AdmissionApplication::query()->sole();
        $response->assertRedirectToRoute('admissions.complete', $application->public_reference);
        $this->assertSame(AdmissionApplicationStatus::Pending, $application->status);
        $this->assertDatabaseCount('student_profiles', 0);
        Notification::assertSentOnDemand(AdmissionApplicationNotification::class);
    }

    public function test_admission_form_rejects_duplicate_and_sensitive_submission(): void
    {
        $token = 'admission-access-token';
        $trial = TrialLessonRequest::factory()->create(['status' => TrialLessonStatus::Completed, 'active_slot_key' => null, 'access_token_hash' => hash('sha256', $token)]);

        $this->post(route('admissions.store', [$trial->public_reference, $token]), [
            ...$this->validPayload($trial),
            'notes' => '口座番号を送ります',
        ])->assertSessionHasErrors('notes');
        $this->post(route('admissions.store', [$trial->public_reference, $token]), $this->validPayload($trial))->assertRedirect();
        $this->post(route('admissions.store', [$trial->public_reference, $token]), $this->validPayload($trial))->assertSessionHasErrors('email');

        $this->assertDatabaseCount('admission_applications', 1);
    }

    public function test_admin_approval_creates_student_account_and_regular_enrollment_with_password_setup(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $application = AdmissionApplication::factory()->create([
            'lesson_type' => LessonType::Regular,
            'pricing_category' => PricingCategory::Standard,
            'monthly_lesson_count' => 4,
            'weekday' => 3,
            'starts_at_time' => '18:00:00',
        ]);

        $this->actingAs($admin)->patch(route('staff.admission-applications.update', $application), [
            'status' => 'approved',
            'pricing_category' => PricingCategory::Junior->value,
        ])->assertSessionHas('success');

        $application->refresh();
        $this->assertSame(AdmissionApplicationStatus::Approved, $application->status);
        $this->assertModelExists($application->convertedUser);
        $this->assertModelExists($application->convertedStudentProfile);
        $this->assertModelExists($application->convertedLessonEnrollment);
        $this->assertSame(PricingCategory::Junior, $application->convertedLessonEnrollment->pricing_category);
        $this->assertSame(4, $application->convertedLessonEnrollment->monthly_lesson_limit);
        $this->assertSame(3, $application->convertedLessonEnrollment->weekday);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $application->email_normalized]);
        Notification::assertSentOnDemand(InitialPasswordSetupNotification::class);
    }

    public function test_flex_approval_creates_enrollment_without_fixed_schedule(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $application = AdmissionApplication::factory()->create(['lesson_type' => LessonType::Flex, 'weekday' => null, 'starts_at_time' => null]);

        $this->actingAs($admin)->patch(route('staff.admission-applications.update', $application), ['status' => 'approved'])->assertSessionHas('success');

        $enrollment = $application->refresh()->convertedLessonEnrollment;
        $this->assertSame(LessonType::Flex, $enrollment->lesson_type);
        $this->assertNull($enrollment->weekday);
        $this->assertNull($enrollment->starts_at_time);
    }

    public function test_existing_email_stops_conversion_and_teacher_cannot_approve(): void
    {
        $application = AdmissionApplication::factory()->create(['email' => 'Existing@Example.test', 'email_normalized' => 'existing@example.test']);
        User::factory()->create(['email' => 'existing@example.test']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('staff.admission-applications.update', $application), ['status' => 'approved'])->assertSessionHasErrors('email');
        $this->assertNull($application->refresh()->converted_user_id);

        $teacher = TeacherProfile::factory()->create();
        $this->actingAs($teacher->user)->patch(route('staff.admission-applications.update', $application), ['status' => 'approved'])->assertForbidden();
    }

    public function test_admin_can_reject_and_teacher_can_view_without_processing(): void
    {
        Notification::fake();
        $application = AdmissionApplication::factory()->create();
        $teacher = TeacherProfile::factory()->create();

        $this->actingAs($teacher->user)->get(route('staff.admission-applications.show', $application))->assertOk();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patch(route('staff.admission-applications.update', $application), ['status' => 'rejected', 'rejection_reason' => '受入調整が必要'])->assertSessionHas('success');

        $this->assertSame(AdmissionApplicationStatus::Rejected, $application->refresh()->status);
        $this->assertSame('受入調整が必要', $application->rejection_reason);
        Notification::assertSentOnDemand(AdmissionApplicationNotification::class);
    }

    public function test_initial_password_link_is_expiring_and_single_use(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'new-student@example.test']);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Secure-password-2026!',
            'password_confirmation' => 'Secure-password-2026!',
        ])->assertRedirectToRoute('login');

        $this->assertTrue(Hash::check('Secure-password-2026!', $user->refresh()->password));
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Another-password-2026!',
            'password_confirmation' => 'Another-password-2026!',
        ])->assertSessionHasErrors('email');
    }

    /** @return array<string, mixed> */
    private function validPayload(TrialLessonRequest $trial): array
    {
        $course = $trial->lessonSlot->course ?? Course::factory()->create();
        $venue = $trial->lessonSlot->venue ?? Venue::factory()->create();
        $teacher = $trial->lessonSlot->teacherProfile ?? TeacherProfile::factory()->create();

        return [
            'name' => $trial->name,
            'name_kana' => $trial->name_kana,
            'email' => $trial->email,
            'phone' => $trial->phone,
            'postal_code' => '184-0004',
            'address' => '東京都小金井市本町1-1-1',
            'course_id' => $course->id,
            'lesson_type' => 'regular',
            'pricing_category' => 'standard',
            'monthly_lesson_count' => 2,
            'lesson_minutes' => 60,
            'venue_id' => $venue->id,
            'teacher_profile_id' => $teacher->id,
            'preferred_start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'weekday' => 3,
            'starts_at_time' => '18:00',
            'notes' => '入会希望です。',
            'privacy_accepted' => '1',
            'website' => '',
        ];
    }
}
