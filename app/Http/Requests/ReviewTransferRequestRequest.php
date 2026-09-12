<?php

namespace App\Http\Requests;

use App\Enums\ApplicationStatus;
use App\Models\TransferRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTransferRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $transferRequest = $this->route('transfer_request');

        return $transferRequest instanceof TransferRequest
            && ($this->user()?->can('update', $transferRequest) ?? false);
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
