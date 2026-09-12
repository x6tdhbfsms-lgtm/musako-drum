<?php

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffCalendarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Teacher, UserRole::Admin) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in(['month', 'week', 'day'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'teacher_profile_id' => ['nullable', 'integer', Rule::exists('teacher_profiles', 'id')],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')],
            'reservation_status' => ['nullable', Rule::enum(ReservationStatus::class)],
        ];
    }
}
