<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\PersonalInformationChangeRequest;
use App\Models\StudentProfile;
use App\Services\MusakoNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePersonalInformationChangeRequest
{
    public function __construct(private readonly MusakoNotificationService $notifications) {}

    public function handle(StudentProfile $studentProfile, array $data): PersonalInformationChangeRequest
    {
        $request = DB::transaction(function () use ($studentProfile, $data): PersonalInformationChangeRequest {
            $profile = StudentProfile::query()->with('user')->lockForUpdate()->findOrFail($studentProfile->id);
            if ($profile->personalInformationChangeRequests()->where('status', ApplicationStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['personal_information' => '承認待ちの個人情報変更申請があります。']);
            }

            $before = [
                'name' => $profile->user->name,
                'email' => $profile->user->email,
                'phone' => $profile->phone,
                'address' => $profile->address,
            ];
            $after = [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
            ];
            if ($before === $after) {
                throw ValidationException::withMessages(['personal_information' => '変更内容を入力してください。']);
            }

            return PersonalInformationChangeRequest::create([
                'student_profile_id' => $profile->id,
                'before_values' => $before,
                'after_values' => $after,
                'student_note' => $data['student_note'] ?? null,
                'status' => ApplicationStatus::Pending,
                'requested_at' => now(),
            ]);
        }, 3);

        $this->notifications->personalInformationChangeSubmitted($request);

        return $request;
    }
}
