<?php

namespace App\Http\Requests;

use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\LessonSlot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLessonSlotRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $lessonSlot = $this->route('lesson_slot');

        return $lessonSlot instanceof LessonSlot && ($this->user()?->can('update', $lessonSlot) ?? false);
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
            'status' => ['required', Rule::enum(LessonSlotStatus::class)],
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

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lessonSlot = $this->route('lesson_slot');

                if (! $lessonSlot instanceof LessonSlot || $validator->errors()->has('capacity')) {
                    return;
                }

                $approvedCount = $lessonSlot->reservationRequests()->where('status', ReservationStatus::Approved)->count();

                if ($this->integer('capacity') < $approvedCount) {
                    $validator->errors()->add('capacity', "承認済み予約が{$approvedCount}件あるため、定員をそれ未満にはできません。");
                }
            },
        ];
    }
}
