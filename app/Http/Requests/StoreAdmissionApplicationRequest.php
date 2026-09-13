<?php

namespace App\Http\Requests;

use App\Enums\LessonType;
use App\Enums\PricingCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdmissionApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'name_kana' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'address' => ['required', 'string', 'max:255'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->where('is_active', true)],
            'lesson_type' => ['required', Rule::enum(LessonType::class)],
            'pricing_category' => ['required', Rule::enum(PricingCategory::class)],
            'monthly_lesson_count' => ['required', 'integer', 'between:1,12'],
            'lesson_minutes' => ['required', 'integer', 'between:15,180'],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('is_active', true)],
            'teacher_profile_id' => ['nullable', 'integer', Rule::exists('teacher_profiles', 'id')],
            'preferred_start_date' => ['required', 'date', 'after_or_equal:today'],
            'weekday' => ['nullable', 'integer', 'between:0,6', Rule::requiredIf($this->input('lesson_type') === LessonType::Regular->value)],
            'starts_at_time' => ['nullable', 'date_format:H:i', Rule::requiredIf($this->input('lesson_type') === LessonType::Regular->value)],
            'notes' => ['nullable', 'string', 'max:1000', 'not_regex:/(?:カード番号|口座番号|暗証番号|CVV|セキュリティコード)/u'],
            'privacy_accepted' => ['accepted'],
            'website' => ['nullable', 'max:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'privacy_accepted.accepted' => 'プライバシーポリシーへの同意が必要です。',
            'notes.not_regex' => '備考にカード番号・口座番号などの機微情報は入力しないでください。',
            'weekday.required' => 'レギュラー契約では固定曜日を選択してください。',
            'starts_at_time.required' => 'レギュラー契約では固定時間を入力してください。',
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $text = mb_convert_kana($this->string('notes')->toString(), 'n');
            $withoutSeparators = preg_replace('/[\s\p{Pd}ー−]/u', '', $text) ?? $text;
            if (preg_match('/\d{7,}/', $withoutSeparators) === 1) {
                $validator->errors()->add('notes', '備考にカード番号・口座番号などの機微情報は入力しないでください。');
            }
        }];
    }
}
