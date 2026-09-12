<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Models\LessonEnrollment;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonalInformationAndPaymentRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_personal_information_changes_only_after_admin_approval_and_keeps_both_values(): void
    {
        $student = StudentProfile::factory()->create(['phone' => '090-1111-1111', 'address' => '東京都小金井市']);
        $student->user->update(['name' => '変更前氏名', 'email' => 'before@example.test']);

        $this->actingAs($student->user)->post(route('student.personal-information-change-requests.store'), [
            'name' => '変更後氏名',
            'email' => 'after@example.test',
            'phone' => '090-2222-2222',
            'address' => '東京都武蔵野市',
        ])->assertRedirectToRoute('student.personal-information-change-requests.index');

        $request = PersonalInformationChangeRequest::query()->firstOrFail();
        $this->assertSame('変更前氏名', $student->user->fresh()->name);
        $this->assertSame('変更前氏名', $request->before_values['name']);
        $this->assertSame('変更後氏名', $request->after_values['name']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patch(route('staff.personal-information-change-requests.update', $request), [
            'decision' => ApplicationStatus::Approved->value,
        ])->assertRedirectToRoute('staff.personal-information-change-requests.show', $request);

        $this->assertSame('変更後氏名', $student->user->fresh()->name);
        $this->assertSame('after@example.test', $student->user->fresh()->email);
        $this->assertSame('090-2222-2222', $student->fresh()->phone);
        $this->assertSame('東京都武蔵野市', $student->fresh()->address);
        $this->assertSame(ApplicationStatus::Approved, $request->fresh()->status);
    }

    public function test_duplicate_personal_information_request_is_rejected(): void
    {
        $student = StudentProfile::factory()->create();
        PersonalInformationChangeRequest::create([
            'student_profile_id' => $student->id,
            'before_values' => ['name' => $student->user->name],
            'after_values' => ['name' => '申請中'],
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
        ]);

        $this->actingAs($student->user)->post(route('student.personal-information-change-requests.store'), [
            'name' => '二件目',
            'email' => $student->user->email,
            'phone' => $student->phone,
            'address' => $student->address,
        ])->assertSessionHasErrors('personal_information');

        $this->assertDatabaseCount('personal_information_change_requests', 1);
    }

    public function test_rejected_personal_information_request_keeps_original_profile_and_reason(): void
    {
        $student = StudentProfile::factory()->create();
        $request = PersonalInformationChangeRequest::create([
            'student_profile_id' => $student->id,
            'before_values' => ['name' => $student->user->name],
            'after_values' => ['name' => '変更後'],
            'status' => ApplicationStatus::Pending,
            'requested_at' => now(),
        ]);
        $originalName = $student->user->name;
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('staff.personal-information-change-requests.update', $request), [
            'decision' => ApplicationStatus::Rejected->value,
            'rejection_reason' => '本人確認が必要です。',
        ]);

        $this->assertSame($originalName, $student->user->fresh()->name);
        $this->assertSame('本人確認が必要です。', $request->fresh()->rejection_reason);
        $this->assertSame(ApplicationStatus::Rejected, $request->fresh()->status);
    }

    public function test_payment_request_stores_no_card_or_bank_account_fields_and_applies_only_the_method(): void
    {
        $student = StudentProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create(['starts_on' => today()->subMonth()]);

        $this->actingAs($student->user)->post(route('student.payment-method-change-requests.store'), [
            'lesson_enrollment_id' => $enrollment->id,
            'requested_method' => PaymentMethod::CreditCard->value,
            'student_note' => '外部決済の案内を希望',
            'card_number' => '4111111111111111',
            'bank_account_number' => '1234567',
        ])->assertRedirectToRoute('student.payment-method-change-requests.index');

        $request = PaymentMethodChangeRequest::query()->firstOrFail();
        $this->assertSame(PaymentMethod::CreditCard, $request->requested_method);
        $this->assertFalse(Schema::hasColumn('payment_method_change_requests', 'card_number'));
        $this->assertFalse(Schema::hasColumn('payment_method_change_requests', 'bank_account_number'));
        $this->assertNull($enrollment->fresh()->payment_method);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patch(route('staff.payment-method-change-requests.update', $request), [
            'decision' => ApplicationStatus::Approved->value,
        ]);

        $this->assertSame(PaymentMethod::CreditCard, $enrollment->fresh()->payment_method);
        $this->assertNotNull($request->fresh()->applied_at);
    }

    public function test_student_cannot_submit_payment_change_for_another_students_contract(): void
    {
        $student = StudentProfile::factory()->create();
        $other = StudentProfile::factory()->create();
        $otherEnrollment = LessonEnrollment::factory()->for($other)->create(['starts_on' => today()->subMonth()]);

        $this->actingAs($student->user)->post(route('student.payment-method-change-requests.store'), [
            'lesson_enrollment_id' => $otherEnrollment->id,
            'requested_method' => PaymentMethod::Cash->value,
        ])->assertSessionHasErrors('lesson_enrollment_id');

        $this->assertDatabaseCount('payment_method_change_requests', 0);
    }

    public function test_payment_note_rejects_card_or_bank_account_like_numbers(): void
    {
        $student = StudentProfile::factory()->create();
        $enrollment = LessonEnrollment::factory()->for($student)->create(['starts_on' => today()->subMonth()]);

        $this->actingAs($student->user)->post(route('student.payment-method-change-requests.store'), [
            'lesson_enrollment_id' => $enrollment->id,
            'requested_method' => PaymentMethod::CreditCard->value,
            'student_note' => 'カード番号 4111-1111-1111-1111 でお願いします',
        ])->assertSessionHasErrors([
            'student_note' => 'カード番号や銀行口座番号は入力しないでください。',
        ]);

        $this->assertDatabaseCount('payment_method_change_requests', 0);
    }
}
