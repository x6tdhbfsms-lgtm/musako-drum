<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\MonthlyInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', MonthlyInvoice::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['required', 'date'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'external_payment_provider' => ['nullable', 'string', 'max:80'],
            'external_payment_reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000', 'not_regex:/(?:カード番号|口座番号|暗証番号|CVV|セキュリティコード)/u'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $note = mb_convert_kana($this->string('notes')->toString(), 'n');
            $withoutSeparators = preg_replace('/[\s\p{Pd}ー−]/u', '', $note) ?? $note;
            if (preg_match('/\d{7,}/', $withoutSeparators) === 1) {
                $validator->errors()->add('notes', 'カード番号や銀行口座番号は備考へ入力しないでください。');
            }
        }];
    }
}
