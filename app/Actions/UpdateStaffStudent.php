<?php

namespace App\Actions;

use App\Enums\ApplicationStatus;
use App\Models\AdmissionApplication;
use App\Models\PersonalInformationChangeRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateStaffStudent
{
    public function revision(StudentProfile $student): string
    {
        return hash('sha256', json_encode($this->snapshot($student), JSON_THROW_ON_ERROR));
    }

    private function snapshot(StudentProfile $student): array
    {
        return [...$student->user->only(['name', 'login_name']), ...$student->only(['phone', 'postal_code', 'address'])];
    }

    public function handle(StudentProfile $student, User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $student);
        try {
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
                if (User::where('login_name', $data['login_name'])->whereKeyNot($user->id)->exists()
                    || AdmissionApplication::where('pending_login_name', $data['login_name'])->exists()) {
                    throw ValidationException::withMessages(['login_name' => 'このログイン名は登録済み、または入会申請中です。']);
                }
                $before = $this->snapshot($profile);
                $after = array_replace($before, array_intersect_key($data, $before));
                if ($before === $after && empty($data['password'])) {
                    return;
                }
                $user->fill(array_intersect_key($after, array_flip(['name', 'login_name'])));
                if (! empty($data['password'])) {
                    $user->password = $data['password'];
                    $user->remember_token = Str::random(60);
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                $user->save();
                $profile->update(array_intersect_key($after, array_flip(['phone', 'postal_code', 'address'])));
                PersonalInformationChangeRequest::create([
                    'student_profile_id' => $profile->id, 'before_values' => $before, 'after_values' => $after,
                    'student_note' => '管理者による生徒情報の直接編集', 'status' => ApplicationStatus::Approved,
                    'requested_at' => now(), 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'login_name')) {
                throw ValidationException::withMessages(['login_name' => 'このログイン名は使用済みです。別のログイン名を設定してください。']);
            }
            throw $exception;
        }
    }
}
