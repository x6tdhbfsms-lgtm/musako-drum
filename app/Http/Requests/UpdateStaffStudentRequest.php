<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login_name'))) {
            $this->merge(['login_name' => mb_strtolower(trim($this->input('login_name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'login_name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]{2,63}$/', Rule::unique('users')->ignore($this->route('student')->user_id)],
            'password' => ['nullable', 'string', 'min:12', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('パスワードは72バイト以内で入力してください（日本語は1文字3バイト程度です）。');
                }
            }],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'address' => ['nullable', 'string', 'max:255'],
            'revision' => ['required', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return [...(new StoreAdmissionApplicationRequest)->messages(), 'unique' => 'このログイン名は別のアカウントで使用されています。', 'revision.required' => '画面を開き直してから保存してください。'];
    }

    public function attributes(): array
    {
        return (new StoreAdmissionApplicationRequest)->attributes();
    }
}
