<?php

namespace Tests\Feature;

use App\Actions\UpdateStaffStudent;
use App\Enums\ApplicationStatus;
use App\Enums\ContractChangeType;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\UserRole;
use App\Models\ContractChangeRequest;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\MonthlyInvoice;
use App\Models\PersonalInformationChangeRequest;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use App\Services\FlexInactivityWarning;
use App\Services\MonthlyInvoiceGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StaffStudentEditingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_updates_basic_information_and_records_actor_and_before_values(): void
    {
        $student = StudentProfile::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $oldName = $student->user->name;
        $password = $student->user->password;
        $data = $this->profileData($student);
        $this->actingAs($admin)->get(route('staff.students.edit', $student))->assertOk()->assertSee('基本情報を保存');
        $this->patch(route('staff.students.update', $student), [...$data, 'role' => 'admin', 'password' => 'injected'])
            ->assertRedirect(route('staff.students.show', $student));
        $this->assertSame('編集確認', $student->user->fresh()->name);
        $this->assertSame('edited@example.invalid', $student->user->fresh()->email);
        $this->assertSame($password, $student->user->fresh()->password);
        $this->assertSame(UserRole::Student, $student->user->fresh()->role);
        $this->assertSame('184-0000', $student->fresh()->postal_code);
        $audit = PersonalInformationChangeRequest::sole();
        $this->assertSame($oldName, $audit->before_values['name']);
        $this->assertSame($admin->id, $audit->reviewed_by_user_id);
        $this->assertSame(ApplicationStatus::Approved, $audit->status);
        $this->assertNull($student->user->fresh()->email_verified_at);
        $this->patch(route('staff.students.update', $student), $data)->assertSessionHasErrors('revision');
        $this->assertDatabaseCount('personal_information_change_requests', 1);
    }

    #[TestWith(['teacher'])]
    #[TestWith(['student'])]
    public function test_non_admin_cannot_open_or_submit_either_editor(string $role): void
    {
        [$enrollment, $data] = $this->contractData();
        $student = $enrollment->studentProfile;
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route('staff.students.edit', $student))->assertForbidden();
        $this->patch(route('staff.students.update', $student), $this->profileData($student))->assertForbidden();
        $this->get(route('staff.students.enrollments.edit', [$student, $enrollment]))->assertForbidden();
        $this->patch(route('staff.students.enrollments.update', [$student, $enrollment]), $data)->assertForbidden();
        $this->assertDatabaseCount('contract_change_requests', 0);
        $this->assertDatabaseCount('personal_information_change_requests', 0);
    }

    public function test_duplicate_email_and_pending_profile_request_do_not_change_data(): void
    {
        $student = StudentProfile::factory()->create();
        $original = $student->user->name;
        User::factory()->create(['email' => 'edited@example.invalid']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $data = $this->profileData($student);
        $this->patch(route('staff.students.update', $student), $data)->assertSessionHasErrors('email');
        PersonalInformationChangeRequest::create(['student_profile_id' => $student->id, 'status' => 'pending', 'before_values' => ['name' => $original], 'after_values' => ['name' => '申請中氏名'], 'requested_at' => now()]);
        $this->patch(route('staff.students.update', $student), [...$data, 'email' => $student->user->email])->assertSessionHasErrors('name');
        $this->assertSame($original, $student->user->fresh()->name);
    }

    public function test_contract_edit_creates_effective_version_and_keeps_continuous_flex_baseline(): void
    {
        [$enrollment, $data] = $this->contractData();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $teacher = TeacherProfile::factory()->create();
        $this->actingAs($admin)->get(route('staff.students.enrollments.edit', [$enrollment->studentProfile, $enrollment]))->assertOk();
        $this->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'teacher_profile_id' => $teacher->id])
            ->assertRedirect(route('staff.students.show', $enrollment->student_profile_id));
        $replacement = $enrollment->replacements()->sole();
        $this->assertSame('2026-10-31', $enrollment->fresh()->ends_on->toDateString());
        $this->assertSame(4, $enrollment->fresh()->monthly_lesson_limit);
        $this->assertSame('2026-11-01', $replacement->starts_on->toDateString());
        $this->assertSame(2, $replacement->monthly_lesson_limit);
        $this->assertSame(45, $replacement->lesson_minutes);
        $this->assertSame($teacher->id, $replacement->teacher_profile_id);
        $this->assertNull($replacement->regular_week_numbers);
        $change = ContractChangeRequest::sole();
        $this->assertSame(ContractChangeType::AdministrativeEdit, $change->type);
        $this->assertSame(ApplicationStatus::Approved, $change->status);
        $this->assertSame($admin->id, $change->reviewed_by_user_id);
        $this->travelTo(Carbon::parse('2026-11-01', 'Asia/Tokyo'));
        $this->assertTrue(app(FlexInactivityWarning::class)->appliesTo($enrollment->studentProfile));
        $this->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), $data)->assertSessionHasErrors('effective_on');
        $this->assertDatabaseCount('lesson_enrollments', 2);
    }

    #[TestWith(['pricing_category', 'junior', 'pricing_category'])]
    #[TestWith(['monthly_lesson_limit', 0, 'monthly_lesson_limit'])]
    #[TestWith(['effective_on', '2026-01-01', 'effective_on'])]
    #[TestWith(['reason', '', 'reason'])]
    public function test_invalid_contract_changes_are_rejected(string $field, mixed $value, string $error): void
    {
        [$enrollment, $data] = $this->contractData();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, $field => $value])->assertSessionHasErrors($error);
        $this->assertDatabaseCount('lesson_enrollments', 1);
        $this->assertDatabaseCount('contract_change_requests', 0);
    }

    #[TestWith([1, '11:45', false])]
    #[TestWith([1, '20:15', true])]
    #[TestWith([1, '20:30', false])]
    #[TestWith([0, '09:45', false])]
    #[TestWith([0, '19:15', true])]
    #[TestWith([0, '19:30', false])]
    #[TestWith([1, '12:10', false])]
    public function test_regular_contract_respects_opening_hours_and_fifteen_minute_starts(int $weekday, string $time, bool $valid): void
    {
        [$enrollment, $data] = $this->contractData();
        $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'lesson_type' => 'regular', 'weekday' => $weekday, 'starts_at_time' => $time]);
        $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('starts_at_time');
        $this->assertDatabaseCount('lesson_enrollments', $valid ? 2 : 1);
    }

    public function test_another_students_contract_cannot_be_edited_through_the_wrong_profile(): void
    {
        [$enrollment, $data] = $this->contractData();
        $other = StudentProfile::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('staff.students.enrollments.update', [$other, $enrollment]), $data)->assertNotFound();
        $this->assertDatabaseCount('contract_change_requests', 0);
    }

    public function test_pending_contract_request_blocks_direct_edit_and_students_cannot_submit_admin_type(): void
    {
        [$enrollment, $data] = $this->contractData();
        ContractChangeRequest::create(['student_profile_id' => $enrollment->student_profile_id, 'lesson_enrollment_id' => $enrollment->id, 'status' => 'pending', 'type' => 'monthly_lessons', 'effective_on' => '2026-12-01', 'before_values' => ['monthly_lesson_limit' => 4], 'after_values' => ['monthly_lesson_limit' => 2], 'requested_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), $data)->assertSessionHasErrors('effective_on');
        $this->actingAs($enrollment->studentProfile->user)
            ->post(route('student.contract-change-requests.store'), [...$data, 'type' => 'administrative_edit', 'lesson_enrollment_id' => $enrollment->id])->assertSessionHasErrors('type');
        $this->assertDatabaseCount('lesson_enrollments', 1);
    }

    public function test_course_change_derives_minutes_and_preserves_existing_reservations_and_invoices(): void
    {
        [$enrollment, $data] = $this->contractData();
        $course = Course::factory()->create(['code' => 'ELECTRONIC-30', 'default_lesson_minutes' => 30]);
        $slot = LessonSlot::factory()->create(['course_id' => $enrollment->course_id, 'starts_at' => '2026-11-05 13:00', 'ends_at' => '2026-11-05 13:45']);
        $reservation = ReservationRequest::factory()->create(['student_profile_id' => $enrollment->student_profile_id, 'lesson_enrollment_id' => $enrollment->id, 'lesson_slot_id' => $slot->id, 'status' => 'approved', 'studio_fee_amount' => 1000]);
        $invoice = MonthlyInvoice::create(['student_profile_id' => $enrollment->student_profile_id, 'status' => 'confirmed', 'total_amount' => 21000, 'billing_month' => '2026-11-01', 'generated_at' => now()]);
        $before = $invoice->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'course_id' => $course->id, 'lesson_minutes' => 180, 'total_amount' => 0])->assertSessionHasNoErrors();
        $this->assertSame(30, $enrollment->replacements()->sole()->lesson_minutes);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($enrollment->id, $reservation->fresh()->lesson_enrollment_id);
        $this->assertSame(1000, $reservation->fresh()->studio_fee_amount);
        $this->assertSame('2026-11-05 13:00', $slot->fresh()->starts_at->format('Y-m-d H:i'));
    }

    #[TestWith(['12:15', false])]
    #[TestWith(['12:30', true])]
    public function test_studio_contract_requires_matching_venue_and_half_hour_start(string $time, bool $valid): void
    {
        [$enrollment, $data] = $this->contractData();
        $course = Course::factory()->create(['code' => 'INDIVIDUAL-60', 'default_lesson_minutes' => 60]);
        $venue = Venue::factory()->create(['lesson_room_type' => 'studio34']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'course_id' => $course->id])->assertSessionHasErrors('venue_id');
        $response = $this->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'course_id' => $course->id, 'venue_id' => $venue->id, 'lesson_type' => 'regular', 'weekday' => 1, 'starts_at_time' => $time]);
        $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('starts_at_time');
    }

    public function test_paused_contract_cannot_be_reactivated_by_editing(): void
    {
        [$enrollment, $data] = $this->contractData();
        $enrollment->update(['status' => 'paused']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'status' => 'active'])->assertSessionHasErrors('effective_on');
        $this->assertSame(EnrollmentStatus::Paused, $enrollment->fresh()->status);
    }

    public function test_guest_cannot_access_editors_and_unchanged_save_has_no_audit_side_effect(): void
    {
        $student = StudentProfile::factory()->create();
        $this->get(route('staff.students.edit', $student))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('staff.students.update', $student), [...$this->profileData($student), 'name' => $student->user->name, 'email' => $student->user->email, 'phone' => $student->phone, 'postal_code' => $student->postal_code, 'address' => $student->address])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('personal_information_change_requests', 0);
    }

    private function profileData(StudentProfile $student): array
    {
        return ['name' => '編集確認', 'email' => 'edited@example.invalid', 'phone' => '00000000000', 'postal_code' => '184-0000', 'address' => '試験専用住所', 'revision' => app(UpdateStaffStudent::class)->revision($student)];
    }

    public function test_setting_fixed_time_immediately_confirms_lessons_visible_in_student_reservations(): void
    {
        Notification::fake();
        [$enrollment, $data] = $this->contractData();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [
            ...$data, 'lesson_type' => 'regular', 'weekday' => 5, 'starts_at_time' => '16:00', 'monthly_lesson_limit' => 4,
        ])->assertSessionHasNoErrors();
        $reservations = ReservationRequest::where('student_profile_id', $enrollment->student_profile_id)->with('lessonSlot')->get();
        $this->assertCount(8, $reservations);
        $this->assertTrue($reservations->every(fn ($row) => $row->status->value === 'approved'));
        $this->assertSame(['2026-11-06 16:00', '2026-11-13 16:00', '2026-11-20 16:00', '2026-11-27 16:00', '2026-12-04 16:00', '2026-12-11 16:00', '2026-12-18 16:00', '2026-12-25 16:00'], $reservations->sortBy('lessonSlot.starts_at')->map(fn ($row) => $row->lessonSlot->starts_at->format('Y-m-d H:i'))->values()->all());
        $this->actingAs($enrollment->studentProfile->user)->get(route('student.reservations.index'))->assertOk()->assertSee('16:00');
        $this->get(route('student.dashboard', ['date' => '2026-11-01']))->assertOk();
        $this->actingAs($admin)->get(route('staff.dashboard', ['date' => '2026-11-01']))->assertOk()->assertSee($enrollment->studentProfile->user->name);
    }

    #[TestWith([30, 5500])]
    #[TestWith([45, 7600])]
    public function test_admin_can_select_kids_and_invoice_uses_kids_amount(int $minutes, int $amount): void
    {
        Notification::fake();
        [$enrollment, $data] = $this->contractData();
        $enrollment->course->update(['code' => 'ELECTRONIC-'.$minutes, 'default_lesson_minutes' => $minutes]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'pricing_category' => 'kids'])->assertSessionHasNoErrors();
        $replacement = $enrollment->replacements()->sole();
        $this->assertSame('kids', $replacement->pricing_category->value);
        $invoice = app(MonthlyInvoiceGenerator::class)->generateForStudent($enrollment->studentProfile, CarbonImmutable::parse('2026-11-01'), $admin);
        $this->assertSame($amount, (int) $invoice->items()->where('type', 'lesson_fee')->sum('amount'));
    }

    public function test_kids_price_change_preserves_already_confirmed_regular_lessons(): void
    {
        Notification::fake();
        [$enrollment, $data] = $this->contractData();
        $admin = User::factory()->admin()->create();
        $data = [...$data, 'lesson_type' => 'regular', 'weekday' => 5, 'starts_at_time' => '16:00'];
        $this->actingAs($admin)->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), $data)->assertSessionHasNoErrors();
        $replacement = $enrollment->replacements()->sole();
        $this->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $replacement]), [...$data, 'effective_on' => '2026-12-01', 'pricing_category' => 'kids'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reservation_requests', 4);
        $invoice = app(MonthlyInvoiceGenerator::class)->generateForStudent($enrollment->studentProfile, CarbonImmutable::parse('2026-12-01'), $admin);
        $this->assertSame(7600, (int) $invoice->items()->where('type', 'lesson_fee')->sum('amount'));
    }

    public function test_kids_cannot_be_selected_for_sixty_minute_electronic_lessons(): void
    {
        [$enrollment, $data] = $this->contractData();
        $enrollment->course->update(['code' => 'ELECTRONIC-60', 'default_lesson_minutes' => 60]);
        $this->actingAs(User::factory()->admin()->create())->patch(route('staff.students.enrollments.update', [$enrollment->studentProfile, $enrollment]), [...$data, 'pricing_category' => 'kids'])->assertSessionHasErrors('pricing_category');
        $this->assertDatabaseCount('lesson_enrollments', 1);
    }

    private function contractData(): array
    {
        $this->travelTo(Carbon::parse('2026-10-17', 'Asia/Tokyo'));
        $course = Course::factory()->create(['code' => 'ELECTRONIC-45', 'default_lesson_minutes' => 45]);
        $venue = Venue::factory()->create(['lesson_room_type' => 'music_room']);
        $teacher = TeacherProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->create(['course_id' => $course->id, 'venue_id' => $venue->id, 'teacher_profile_id' => $teacher->id, 'lesson_type' => LessonType::Flex, 'lesson_minutes' => 45, 'starts_on' => '2026-01-01']);

        return [$enrollment, ['course_id' => $course->id, 'lesson_type' => 'flex', 'pricing_category' => 'standard', 'monthly_lesson_limit' => 2, 'venue_id' => $venue->id, 'teacher_profile_id' => $teacher->id, 'payment_method' => 'cash', 'effective_on' => '2026-11-01', 'weekday' => null, 'starts_at_time' => null, 'reason' => '管理者による試験変更']];
    }
}
