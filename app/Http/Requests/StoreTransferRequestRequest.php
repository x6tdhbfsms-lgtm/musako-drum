<?php

namespace App\Http\Requests;

use App\Models\ReservationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reservationRequest = $this->route('reservation_request');

        return $reservationRequest instanceof ReservationRequest
            && ($this->user()?->can('requestTransfer', $reservationRequest) ?? false);
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
            'requested_starts_at.prohibits' => '振替先は1つだけ選択してください。',
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
            'requested_starts_at' => ['exclude_if:defer_schedule,1', 'required_without:requested_lesson_slot_id', 'nullable', 'date_format:Y-m-d H:i', 'prohibits:requested_lesson_slot_id'],
            'requested_lesson_slot_id' => ['exclude_if:defer_schedule,1', 'required_without:requested_starts_at', 'nullable', 'integer', Rule::exists('lesson_slots', 'id')],
            'defer_schedule' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:special_handling,1'],
            'special_handling' => ['sometimes', 'boolean'],
            'student_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
