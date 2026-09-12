<?php

namespace App\Http\Requests;

use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelReservationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reservationRequest = $this->route('reservation_request');

        return $reservationRequest instanceof ReservationRequest
            && ($this->user()?->can('delete', $reservationRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
