<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Models\LessonEnrollment;
use App\Models\PaymentMethodChangeRequest;
use App\Models\StudentProfile;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePaymentMethodChangeRequest
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    public function handle(StudentProfile $studentProfile, array $data): PaymentMethodChangeRequest
    {
        $request = DB::transaction(function () use ($studentProfile, $data): PaymentMethodChangeRequest {
            StudentProfile::query()->lockForUpdate()->findOrFail($studentProfile->id);
            $enrollment = isset($data['lesson_enrollment_id'])
                ? LessonEnrollment::query()->lockForUpdate()->findOrFail($data['lesson_enrollment_id'])
                : $studentProfile->enrollments()->activeOn(now())->lockForUpdate()->first();

            if ($enrollment !== null && $enrollment->student_profile_id !== $studentProfile->id) {
                throw ValidationException::withMessages(['lesson_enrollment_id' => '選択した契約を確認できません。']);
            }
            if ($studentProfile->paymentMethodChangeRequests()->where('status', ApplicationStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['requested_method' => '承認待ちの支払い方法変更申請があります。']);
            }

            return PaymentMethodChangeRequest::create([
                'student_profile_id' => $studentProfile->id,
                'lesson_enrollment_id' => $enrollment?->id,
                'previous_method' => $enrollment?->payment_method,
                'requested_method' => PaymentMethod::from($data['requested_method']),
                'student_note' => $data['student_note'] ?? null,
                'status' => ApplicationStatus::Pending,
                'requested_at' => now(),
            ]);
        }, 3);

        $this->notifications->paymentMethodChangeSubmitted($request);

        return $request;
    }
}
