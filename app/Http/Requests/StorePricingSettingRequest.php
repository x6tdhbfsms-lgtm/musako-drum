<?php

namespace App\Http\Requests;

use App\Enums\PricingDisplayMode;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingSettingRequest extends FormRequest
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
            'effective_from' => ['required', 'date', 'unique:pricing_settings,effective_from'],
            'display_mode' => ['required', Rule::enum(PricingDisplayMode::class)],
            'normal_admission_fee' => ['nullable', 'integer', 'between:0,10000000'],
            'admission_campaign_enabled' => ['nullable', 'boolean'],
            'admission_campaign_fee' => ['nullable', 'integer', 'between:0,10000000', 'required_if:admission_campaign_enabled,1'],
            'admission_campaign_message' => ['nullable', 'string', 'max:255'],
            'pricing_notice' => ['nullable', 'string', 'max:2000'],
            'lesson_pricing_url' => ['nullable', 'url:http,https', 'max:2048'],
            'studio_pricing_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }
}
