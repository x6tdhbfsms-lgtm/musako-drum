<?php

namespace App\Http\Requests;

use App\Enums\AttendanceNoticeType;
use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('type') === AttendanceNoticeType::Late->value
                    && $this->input('late_minutes') === null
                    && $this->input('expected_arrival_time') === null) {
                    $validator->errors()->add('late_minutes', '遅刻予定分数または到着予定時刻のどちらかを入力してください。');
                }
            },
        ];
    }
}
