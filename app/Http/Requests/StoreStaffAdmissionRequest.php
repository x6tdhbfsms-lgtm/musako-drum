<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Venue;
use Illuminate\Validation\Validator;

class StoreStaffAdmissionRequest extends StoreAdmissionApplicationRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['address'] = ['nullable', 'string', 'max:255'];
        $rules['venue_id'][0] = 'required';
        $rules['teacher_profile_id'][0] = 'required';
        $rules['monthly_lesson_count'] = ['required', 'integer', 'between:1,4'];
        $rules['regular_week_pattern'] = ['nullable', 'string', 'regex:/^[1-4](,[1-4]){0,3}$/'];

        return $rules;
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('lesson_type') === 'regular' && $this->filled('regular_week_pattern')) {
                $weeks = explode(',', $this->input('regular_week_pattern'));
                if (count($weeks) !== $this->integer('monthly_lesson_count') || count(array_unique($weeks)) !== count($weeks)) {
                    $validator->errors()->add('regular_week_pattern', '月回数と同じ数の週を、重複せず選択してください。');
                }
            }
            $course = Course::find($this->integer('course_id'));
            $venue = Venue::find($this->integer('venue_id'));
            $electronic = str_contains($course->code, 'ELECTRONIC-');
            if ($venue->termsRoomType() !== ($electronic ? 'music_room' : 'studio34')) {
                $validator->errors()->add('venue_id', 'コースに対応する会場を選択してください。');
            }
            if ($this->integer('lesson_minutes') !== (int) $course->default_lesson_minutes) {
                $validator->errors()->add('lesson_minutes', 'コースに設定されたレッスン時間を入力してください。');
            }
            if (($electronic && $this->input('pricing_category') === 'junior') || ($this->input('pricing_category') === 'kids' && (! $electronic || ! in_array((int) $course->default_lesson_minutes, [30, 45], true)))) {
                $validator->errors()->add('pricing_category', 'キッズ料金は電子ドラム30分・45分のみ選択できます。電子ドラムにジュニア料金は適用できません。');
            }
            if (! $electronic && $this->input('lesson_type') === 'regular'
                && ! in_array(substr($this->input('starts_at_time'), -2), ['00', '30'], true)) {
                $validator->errors()->add('starts_at_time', 'studio34は00分・30分開始です。');
            }
        }];
    }
}
