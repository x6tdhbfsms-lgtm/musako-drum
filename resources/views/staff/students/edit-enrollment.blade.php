@extends('layouts.app')
@section('title', '契約情報を編集 | MUSAKO')
@section('content')
<div class="mx-auto max-w-4xl space-y-6">
<a href="{{ route('staff.students.edit', $student) }}" class="text-sm font-bold text-amber-700">← 生徒情報の編集へ戻る</a>
<h1 class="text-2xl font-bold">契約情報を編集</h1>
<p class="break-words text-stone-600">{{ $student->user->name }}さん · {{ $enrollment->course->name }}</p>
<p class="rounded-xl bg-amber-50 p-4 text-sm">適用日以降の契約を更新します。過去の契約・確定済み請求は保持します。作成済みの予約は自動変更されません。変更後はカレンダーを確認し、定期予定は再生成・確認してください。料金は料金設定に基づき計算されます。</p>
<form method="post" action="{{ route('staff.students.enrollments.update', [$student, $enrollment]) }}" class="space-y-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7">
@csrf @method('PATCH')
@if ($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="grid gap-5 sm:grid-cols-2">
<label class="grid min-w-0 gap-2 text-sm font-semibold">適用日<input type="date" name="effective_on" required min="{{ max(today()->toDateString(), $enrollment->starts_on->copy()->addDay()->toDateString()) }}" value="{{ old('effective_on', max(now()->addMonth()->startOfMonth()->toDateString(), $enrollment->starts_on->copy()->addDay()->toDateString())) }}" class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 px-3"><span class="text-xs text-stone-500">現在の契約開始日（{{ $enrollment->starts_on->format('Y/m/d') }}）より後を選択してください。</span></label>
<label class="grid min-w-0 gap-2 text-sm font-semibold">コース・レッスン時間<select name="course_id" required class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 bg-white px-3">@foreach ($courses as $course)<option value="{{ $course->id }}" @selected(old('course_id', $enrollment->course_id) == $course->id)>{{ $course->name }}</option>@endforeach</select></label>
@foreach (['lesson_type' => ['契約タイプ', App\Enums\LessonType::cases()], 'pricing_category' => ['料金区分', App\Enums\PricingCategory::cases()], 'payment_method' => ['支払方法', App\Enums\PaymentMethod::cases()]] as $field => [$label, $options])
<label class="grid min-w-0 gap-2 text-sm font-semibold">{{ $label }}<select name="{{ $field }}" @required($field !== 'payment_method') class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 bg-white px-3">@if ($field === 'payment_method')<option value="">未設定</option>@endif @foreach ($options as $option)<option value="{{ $option->value }}" @selected(old($field, $enrollment->$field?->value) === $option->value)>{{ $option->label() }}</option>@endforeach</select></label>
@endforeach
<label class="grid min-w-0 gap-2 text-sm font-semibold">月回数<input type="number" name="monthly_lesson_limit" min="1" max="4" required value="{{ old('monthly_lesson_limit', $enrollment->monthly_lesson_limit) }}" class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 px-3"></label>
<label class="grid min-w-0 gap-2 text-sm font-semibold">会場<select name="venue_id" required class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 bg-white px-3">@foreach ($venues as $venue)<option value="{{ $venue->id }}" @selected(old('venue_id', $enrollment->venue_id) == $venue->id)>{{ $venue->name }}</option>@endforeach</select></label>
<label class="grid min-w-0 gap-2 text-sm font-semibold">担当講師<select name="teacher_profile_id" required class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 bg-white px-3">@foreach ($teachers as $teacher)<option value="{{ $teacher->id }}" @selected(old('teacher_profile_id', $enrollment->teacher_profile_id) == $teacher->id)>{{ $teacher->display_name }}</option>@endforeach</select></label>
<label class="grid min-w-0 gap-2 text-sm font-semibold">固定曜日<select name="weekday" class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 bg-white px-3"><option value="">フレックスは指定不要</option>@foreach (['日','月','火','水','木','金','土'] as $value => $label)<option value="{{ $value }}" @selected((string) old('weekday', $enrollment->weekday) === (string) $value)>{{ $label }}曜日</option>@endforeach</select></label>
<label class="grid min-w-0 gap-2 text-sm font-semibold">固定開始時間<input type="time" name="starts_at_time" step="900" value="{{ old('starts_at_time', $enrollment->starts_at_time ? substr($enrollment->starts_at_time, 0, 5) : '') }}" class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 px-3"></label>
</div>
<p class="text-sm text-stone-600">レギュラーは曜日・開始時間が必要です。月回数を変更すると、月2回は第1・第3週、月4回は第1〜4週を標準にします。電子ドラムは一般料金のみです。</p>
<label class="grid gap-2 text-sm font-semibold">変更理由<textarea name="reason" required maxlength="1000" rows="3" class="min-w-0 w-full rounded-xl border border-stone-300 p-3">{{ old('reason') }}</textarea></label>
<button class="min-h-12 w-full rounded-xl bg-amber-400 px-5 font-bold">適用日を指定して契約を保存</button>
</form></div>
<p class="mt-4 text-sm text-stone-600">キッズ（3〜7歳）は電子ドラム30分：1回2,750円、45分：1回3,800円。会場代は別途1回1,000円です。料金区分でキッズを選択すると自動適用されます。</p>
@endsection
