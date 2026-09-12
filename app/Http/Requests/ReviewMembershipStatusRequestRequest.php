<?php

namespace App\Http\Requests;

use App\Enums\ApplicationStatus;
use App\Models\MembershipStatusRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewMembershipStatusRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $membershipStatusRequest = $this->route('membership_status_request');

        return $membershipStatusRequest instanceof MembershipStatusRequest
            && ($this->user()?->can('update', $membershipStatusRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([ApplicationStatus::Approved->value, ApplicationStatus::Rejected->value])],
            'staff_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
