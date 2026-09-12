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
            'reason' => ['nullable', 'string', 'max:1000'],
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
