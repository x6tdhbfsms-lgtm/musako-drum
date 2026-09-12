<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\LessonSlot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLessonSlotRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', LessonSlot::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'teacher_profile_id' => [
                Rule::requiredIf($this->user()?->role === UserRole::Admin),
                'nullable',
                'integer',
                Rule::exists('teacher_profiles', 'id'),
            ],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('is_active', true)],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('is_active', true)],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'between:1,20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'starts_at.after' => '開始日時は現在より後を指定してください。',
            'ends_at.after' => '終了日時は開始日時より後を指定してください。',
            'capacity.between' => '定員は1人から20人の範囲で指定してください。',
        ];
    }
}
