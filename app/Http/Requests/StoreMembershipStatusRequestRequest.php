<?php

namespace App\Http\Requests;

use App\Enums\MembershipRequestType;
use App\Models\MembershipStatusRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMembershipStatusRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', MembershipStatusRequest::class) ?? false;
    }

    public function messages(): array
    {
        return [
            'pause_months.required_if' => '休会期間を1〜3か月から選択してください。',
            'pause_months.integer' => '休会期間を1〜3か月から選択してください。',
            'pause_months.between' => '休会期間を1〜3か月から選択してください。',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(MembershipRequestType::class)],
            'effective_on' => ['required', 'date', 'after_or_equal:today'],
            'pause_months' => ['exclude_unless:type,pause', 'required_if:type,pause', 'integer', 'between:1,3'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
