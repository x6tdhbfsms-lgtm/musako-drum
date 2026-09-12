<?php

namespace App\Http\Requests;

use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reservationRequest = $this->route('reservation_request');

        return $reservationRequest instanceof ReservationRequest
            && ($this->user()?->can('requestTransfer', $reservationRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'requested_lesson_slot_id' => ['required', 'integer', Rule::exists('lesson_slots', 'id')],
            'reason' => ['nullable', 'string', 'max:1000'],
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
