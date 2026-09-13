<?php

namespace App\Http\Requests;

use App\Enums\TrialLessonStatus;
use App\Models\TrialLessonRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTrialLessonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $trialLessonRequest = $this->route('trial_lesson_request');

        return $trialLessonRequest instanceof TrialLessonRequest
            && ($this->user()?->can('update', $trialLessonRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                TrialLessonStatus::Approved->value,
                TrialLessonStatus::Rejected->value,
                TrialLessonStatus::Completed->value,
                TrialLessonStatus::NoShow->value,
            ])],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
