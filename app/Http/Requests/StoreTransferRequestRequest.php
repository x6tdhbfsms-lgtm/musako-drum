<?php

namespace App\Http\Requests;

use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransferRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reservationRequest = $this->route('reservation_request');
        $user = $this->user();
        if ($reservationRequest instanceof ReservationRequest && $user?->role?->value === 'student') {
            // Let an owner reach the confirmation screen even if the lesson was
            // cancelled or its deadline passed while the calendar was open.
            // CreateTransferRequest rechecks all eligibility before saving.
            return $user->studentProfile?->is($reservationRequest->studentProfile) === true;
        }

        return $reservationRequest instanceof ReservationRequest
            && ($user?->can('requestTransfer', $reservationRequest) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function messages(): array
    {
        return [
            'reason.required_if' => '特例を希望する場合は、理由を入力してください。通常の振替は特例のチェックを外して申請できます。',
            'required_without' => '振替先の日時を選択してください。',
            'requested_starts_at.date_format' => '振替先の日時を選び直してください。',
            'requested_starts_at.max' => '振替先は第三希望まで選択できます。',
            'requested_starts_at.array' => '振替先を選び直してください。',
            'requested_starts_at.*.date_format' => '振替先の日時を選び直してください。',
            'requested_lesson_slot_id.exists' => 'この振替先は利用できません。日時を選び直してください。',
            'requested_lesson_slot_id.integer' => '振替先の日時を選び直してください。',
            'special_handling.boolean' => '特例希望の選択を確認してください。',
            'reason.string' => '理由は文章で入力してください。',
            'reason.max' => '理由は1,000文字以内で入力してください。',
            'student_note.string' => '備考は文章で入力してください。',
            'student_note.max' => '備考は1,000文字以内で入力してください。',
        ];
    }

    public function rules(): array
    {
        return [
            'requested_starts_at' => ['exclude_if:defer_schedule,1', 'required_without:requested_lesson_slot_id', 'nullable', 'prohibits:requested_lesson_slot_id'],
            'requested_lesson_slot_id' => ['exclude_if:defer_schedule,1', 'required_without:requested_starts_at', 'nullable', 'integer', Rule::exists('lesson_slots', 'id')],
            'defer_schedule' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:special_handling,1'],
            'special_handling' => ['sometimes', 'boolean'],
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $value = $this->input('requested_starts_at');
            if ($value === null) {
                return;
            }
            $values = is_array($value) ? array_values($value) : [$value];
            if (count($values) > 3) {
                $validator->errors()->add('requested_starts_at', '振替先は第三希望まで選択できます。');

                return;
            }
            if (count(array_unique($values)) !== count($values)) {
                $validator->errors()->add('requested_starts_at', '同じ日時を複数の希望に選ぶことはできません。');
            }
            foreach ($values as $time) {
                if (! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $time)) {
                    $validator->errors()->add('requested_starts_at', '振替先の日時を選び直してください.');
                    break;
                }
            }
        });
    }
}
