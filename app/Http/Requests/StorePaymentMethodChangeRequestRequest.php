<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\PaymentMethodChangeRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentMethodChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PaymentMethodChangeRequest::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'lesson_enrollment_id' => ['nullable', 'integer', 'exists:lesson_enrollments,id'],
            'requested_method' => ['required', Rule::enum(PaymentMethod::class)],
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $note = mb_convert_kana($this->string('student_note')->toString(), 'n');
            $withoutSeparators = preg_replace('/[\s\p{Pd}ー−]/u', '', $note) ?? $note;

            if (preg_match('/\d{7,}/', $withoutSeparators) === 1) {
                $validator->errors()->add('student_note', 'カード番号や銀行口座番号は入力しないでください。');
            }
        }];
    }
}
