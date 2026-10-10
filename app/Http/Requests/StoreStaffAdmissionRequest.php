<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\AdmissionApplication;
use App\Models\Course;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Validation\Validator;

class StoreStaffAdmissionRequest extends StoreAdmissionApplicationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['venue_id'][0] = 'required';
        $rules['teacher_profile_id'][0] = 'required';
        $rules['monthly_lesson_count'] = ['required', 'integer', 'between:1,4'];

        return $rules;
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $email = $this->validated('email');
            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()
                || AdmissionApplication::where('pending_email_key', hash('sha256', $email))->exists()) {
                $validator->errors()->add('email', 'このメールアドレスは登録済み、または入会申請中です。');
            }
            $course = Course::find($this->integer('course_id'));
            $venue = Venue::find($this->integer('venue_id'));
            $electronic = str_contains($course->code, 'ELECTRONIC-');
            if ($venue->termsRoomType() !== ($electronic ? 'music_room' : 'studio34')) {
                $validator->errors()->add('venue_id', 'コースに対応する会場を選択してください。');
            }
            if ($this->integer('lesson_minutes') !== (int) $course->default_lesson_minutes) {
                $validator->errors()->add('lesson_minutes', 'コースに設定されたレッスン時間を入力してください。');
            }
            if ($electronic && $this->input('pricing_category') !== 'standard') {
                $validator->errors()->add('pricing_category', '電子ドラムコースは一般料金のみです。');
            }
            if (! $electronic && $this->input('lesson_type') === 'regular'
                && ! in_array(substr($this->input('starts_at_time'), -2), ['00', '30'], true)) {
                $validator->errors()->add('starts_at_time', 'studio34は00分・30分開始です。');
            }
        }];
    }
}
