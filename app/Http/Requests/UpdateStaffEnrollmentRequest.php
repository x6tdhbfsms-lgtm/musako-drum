<?php

namespace App\Http\Requests;

use App\Enums\LessonType;
use App\Enums\PaymentMethod;
use App\Enums\PricingCategory;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\Venue;
use App\Services\LessonOperatingHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student')) ?? false;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->where('is_active', true)],
            'lesson_type' => ['required', Rule::enum(LessonType::class)],
            'pricing_category' => ['required', Rule::enum(PricingCategory::class)],
            'monthly_lesson_limit' => ['required', 'integer', 'between:1,4'],
            'venue_id' => ['required', 'integer', Rule::exists('venues', 'id')->where('is_active', true)],
            'teacher_profile_id' => ['required', 'integer', 'exists:teacher_profiles,id'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'effective_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'weekday' => ['nullable', 'integer', 'between:0,6', 'required_if:lesson_type,regular'],
            'starts_at_time' => ['nullable', 'date_format:H:i', 'required_if:lesson_type,regular'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [...(new StoreAdmissionApplicationRequest)->messages(), 'required_if' => ':attributeを入力・選択してください。'];
    }

    public function attributes(): array
    {
        return [...(new StoreAdmissionApplicationRequest)->attributes(), 'monthly_lesson_limit' => '月回数', 'effective_on' => '適用日', 'payment_method' => '支払方法', 'reason' => '変更理由'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $course = Course::findOrFail($this->integer('course_id'));
            $venue = Venue::findOrFail($this->integer('venue_id'));
            $electronic = str_contains($course->code, 'ELECTRONIC-');
            if (! in_array((int) $course->default_lesson_minutes, $electronic ? [30, 45, 60] : [60], true)) {
                $validator->errors()->add('course_id', 'コースのレッスン時間設定を確認してください。');
            }
            if (! TeacherProfile::whereKey($this->integer('teacher_profile_id'))->whereHas('user', fn ($users) => $users->where('role', 'teacher')->where('account_status', 'active'))->exists()) {
                $validator->errors()->add('teacher_profile_id', '利用可能な講師を選択してください。');
            }
            if ($venue->termsRoomType() !== ($electronic ? 'music_room' : 'studio34')) {
                $validator->errors()->add('venue_id', 'コースに対応する会場を選択してください。');
            }
            if (($electronic && $this->input('pricing_category') === 'junior') || ($this->input('pricing_category') === 'kids' && (! $electronic || ! in_array((int) $course->default_lesson_minutes, [30, 45], true)))) {
                $validator->errors()->add('pricing_category', 'キッズ料金は電子ドラム30分・45分のみ選択できます。電子ドラムにジュニア料金は適用できません。');
            }
            if ($this->input('lesson_type') === 'regular') {
                $start = CarbonImmutable::now('Asia/Tokyo')->startOfWeek(CarbonImmutable::SUNDAY)->addDays($this->integer('weekday'))->setTimeFromTimeString($this->input('starts_at_time'));
                if ($start->minute % ($electronic ? 15 : 30) !== 0) {
                    $validator->errors()->add('starts_at_time', $electronic ? '電子ドラムは15分刻みで指定してください。' : 'studio34は00分・30分開始です。');
                }
                if (! LessonOperatingHours::allows($start, $start->addMinutes($course->default_lesson_minutes))) {
                    $validator->errors()->add('starts_at_time', 'レッスンが'.LessonOperatingHours::DESCRIPTION.'内に終了するよう設定してください。');
                }
            }
        }];
    }
}
