<?php

namespace App\Http\Requests;

use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceRateRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(PriceRateKind::class)],
            'pricing_category' => [
                'nullable',
                Rule::enum(PricingCategory::class),
                'required_if:kind,regular_lesson',
                'prohibited_unless:kind,regular_lesson',
            ],
            'monthly_lesson_count' => [
                'nullable', 'integer', 'between:1,31',
                'required_if:kind,regular_lesson',
                'prohibited_unless:kind,regular_lesson',
            ],
            'amount' => ['required', 'integer', 'between:0,10000000'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
