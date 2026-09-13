<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTrialLessonRequest extends FormRequest
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
            'lesson_slot_id' => ['required', 'integer', Rule::exists('lesson_slots', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'name_kana' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'age_group' => ['required', Rule::in(['under_12', 'teen', 'adult', 'senior'])],
            'drum_experience' => ['required', Rule::in(['none', 'beginner', 'experienced'])],
            'consultation' => ['nullable', 'string', 'max:1000', 'not_regex:/(?:カード番号|口座番号|暗証番号|CVV|セキュリティコード)/u'],
            'privacy_accepted' => ['accepted'],
            'website' => ['nullable', 'max:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'privacy_accepted.accepted' => 'プライバシーポリシーへの同意が必要です。',
            'consultation.not_regex' => '相談内容にカード番号・口座番号などの機微情報は入力しないでください。',
            'website.max' => '送信内容を確認してください。',
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $text = mb_convert_kana($this->string('consultation')->toString(), 'n');
            $withoutSeparators = preg_replace('/[\s\p{Pd}ー−]/u', '', $text) ?? $text;
            if (preg_match('/\d{7,}/', $withoutSeparators) === 1) {
                $validator->errors()->add('consultation', '相談内容にカード番号・口座番号などの機微情報は入力しないでください。');
            }
        }];
    }
}
