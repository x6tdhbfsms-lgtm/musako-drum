<?php

namespace App\Http\Requests;

use App\Enums\ContractChangeType;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Models\ContractChangeRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ContractChangeRequest::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContractChangeType::class)],
            'lesson_enrollment_id' => ['nullable', 'integer', 'exists:lesson_enrollments,id', 'required_unless:type,course_add'],
            'effective_on' => ['required', 'date', 'after:today'],
            'weekday' => ['nullable', 'integer', 'between:0,6', 'required_if:type,schedule', 'required_if:lesson_type,regular'],
            'starts_at_time' => ['nullable', 'date_format:H:i', 'required_if:type,schedule', 'required_if:lesson_type,regular'],
            'monthly_lesson_limit' => ['nullable', 'integer', 'between:1,31', 'required_if:type,monthly_lessons'],
            'lesson_minutes' => ['nullable', 'integer', 'between:15,180', 'required_if:type,lesson_minutes'],
            'course_id' => [
                'nullable',
                'integer',
                Rule::exists('courses', 'id')->where('is_active', true),
                Rule::requiredIf(fn (): bool => in_array($this->input('type'), ['course_change', 'course_add'], true)),
            ],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('is_active', true), 'required_if:type,venue_change'],
            'lesson_type' => ['nullable', Rule::enum(LessonType::class), 'required_if:type,lesson_type'],
            'pricing_category' => ['nullable', Rule::enum(PricingCategory::class), 'required_if:type,pricing_category'],
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
