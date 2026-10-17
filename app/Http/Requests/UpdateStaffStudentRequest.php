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
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users')->ignore($this->route('student')->user_id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'address' => ['nullable', 'string', 'max:255'],
            'revision' => ['required', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return [...(new StoreAdmissionApplicationRequest)->messages(), 'unique' => 'このメールアドレスは別のアカウントで使用されています。', 'revision.required' => '画面を開き直してから保存してください。'];
    }

    public function attributes(): array
    {
        return (new StoreAdmissionApplicationRequest)->attributes();
    }
}
