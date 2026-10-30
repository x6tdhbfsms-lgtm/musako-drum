<?php

namespace App\Http\Requests;

use App\Enums\AttendanceNoticeType;
use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertAttendanceNoticeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reservationRequest = $this->route('reservation_request');

        return $reservationRequest instanceof ReservationRequest
            && ($this->user()?->can('submitAttendanceNotice', $reservationRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(AttendanceNoticeType::class)],
            'late_minutes' => ['nullable', 'integer', 'between:1,180', Rule::prohibitedIf($this->input('type') !== AttendanceNoticeType::Late->value)],
            'expected_arrival_time' => ['nullable', 'date_format:H:i', Rule::prohibitedIf($this->input('type') !== AttendanceNoticeType::Late->value)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
