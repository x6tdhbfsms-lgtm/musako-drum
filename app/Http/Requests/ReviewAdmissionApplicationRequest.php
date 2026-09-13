<?php

namespace App\Http\Requests;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\PricingCategory;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAdmissionApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                AdmissionApplicationStatus::Approved->value,
                AdmissionApplicationStatus::Rejected->value,
            ])],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
            'pricing_category' => ['nullable', Rule::enum(PricingCategory::class)],
        ];
    }
}
