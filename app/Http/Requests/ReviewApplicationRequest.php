<?php

namespace App\Http\Requests;

use App\Enums\ApplicationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = $this->route('contract_change_request')
            ?? $this->route('personal_change')
            ?? $this->route('payment_change');

        return $model !== null && ($this->user()?->can('update', $model) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([ApplicationStatus::Approved->value, ApplicationStatus::Rejected->value])],
            'rejection_reason' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ];
    }
}
