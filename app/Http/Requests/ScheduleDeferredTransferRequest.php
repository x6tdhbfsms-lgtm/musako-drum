<?php

namespace App\Http\Requests;

use App\Models\TransferRequest;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleDeferredTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = $this->route('transfer_request');

        return $transfer instanceof TransferRequest && ($this->user()?->can('update', $transfer) ?? false);
    }

    public function rules(): array
    {
        return ['starts_at' => ['required', 'date_format:Y-m-d\TH:i']];
    }

    public function messages(): array
    {
        return [
            'starts_at.required' => '振替先の日付と開始時刻を入力してください。',
            'starts_at.date_format' => '振替先の日付と開始時刻を正しく入力してください。',
        ];
    }
}
