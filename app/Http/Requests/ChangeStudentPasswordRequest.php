<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeStudentPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->value === 'student';
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('パスワードは72バイト以内で入力してください。');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => '現在のパスワードを入力してください。',
            'current_password.current_password' => '現在のパスワードが正しくありません。',
            'password.required' => '新しいパスワードを入力してください。',
            'password.min' => '新しいパスワードは12文字以上で入力してください。',
            'password.confirmed' => '新しいパスワードと確認用の入力が一致しません。',
        ];
    }
}
