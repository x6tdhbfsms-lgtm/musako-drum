<?php

namespace App\Http\Requests;

use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ReservationRequest::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function lessonSlot(): LessonSlot
    {
        /** @var LessonSlot $lessonSlot */
        $lessonSlot = $this->route('lesson_slot');

        return $lessonSlot;
    }
}
