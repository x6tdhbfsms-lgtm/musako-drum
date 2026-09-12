<?php

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest as ReservationRequestModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewReservationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reservationRequest = $this->route('reservation_request');

        return $reservationRequest instanceof ReservationRequestModel
            && ($this->user()?->can('update', $reservationRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([ReservationStatus::Approved->value, ReservationStatus::Rejected->value])],
            'staff_note' => ['nullable', 'string', 'max:1000'],
            'override_monthly_limit' => ['nullable', 'boolean'],
            'monthly_limit_override_reason' => ['nullable', 'required_if:override_monthly_limit,1', 'string', 'max:255'],
        ];
    }
}
