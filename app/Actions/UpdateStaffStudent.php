<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\AdmissionApplication;
use App\Models\PersonalInformationChangeRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateStaffStudent
{
    public function revision(StudentProfile $student): string
    {
        return hash('sha256', json_encode($this->snapshot($student), JSON_THROW_ON_ERROR));
    }

    private function snapshot(StudentProfile $student): array
    {
        return [...$student->user->only(['name', 'email']), ...$student->only(['phone', 'postal_code', 'address'])];
    }

    public function handle(StudentProfile $student, User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $student);
        DB::transaction(function () use ($student, $actor, $data): void {
            $profile = StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $user = User::query()->lockForUpdate()->findOrFail($profile->user_id);
            $profile->setRelation('user', $user);
            if (! hash_equals($this->revision($profile), $data['revision'])) {
                throw ValidationException::withMessages(['revision' => '別の操作で情報が変更されています。画面を開き直して確認してください。']);
            }
            if ($profile->personalInformationChangeRequests()->where('status', ApplicationStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['name' => '承認待ちの個人情報変更申請があります。先に申請を確認してください。']);
            }
            if (User::whereRaw('LOWER(email) = ?', [$data['email']])->whereKeyNot($user->id)->exists()
                || AdmissionApplication::where('pending_email_key', hash('sha256', $data['email']))->exists()) {
                throw ValidationException::withMessages(['email' => 'このメールアドレスは登録済み、または入会申請中です。']);
            }
            $before = $this->snapshot($profile);
            $after = array_replace($before, array_intersect_key($data, $before));
            if ($before === $after) {
                return;
            }
            $user->fill(array_intersect_key($after, array_flip(['name', 'email'])));
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();
            $profile->update(array_intersect_key($after, array_flip(['phone', 'postal_code', 'address'])));
            PersonalInformationChangeRequest::create([
                'student_profile_id' => $profile->id, 'before_values' => $before, 'after_values' => $after,
                'student_note' => '管理者による生徒情報の直接編集', 'status' => ApplicationStatus::Approved,
                'requested_at' => now(), 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
            ]);
        }, 3);
    }
}
