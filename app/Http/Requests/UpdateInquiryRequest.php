<?php

namespace App\Http\Requests;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('inquiry');

        return $inquiry instanceof Inquiry && ($this->user()?->can('update', $inquiry) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(InquiryStatus::class)],
            'staff_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
