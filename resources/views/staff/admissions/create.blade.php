@extends('layouts.app')
@section('title', '新規生徒登録 | MUSAKO')
@section('content')
<div class="mx-auto max-w-4xl space-y-6">
<a href="{{ route('staff.students.index') }}" class="text-sm font-bold text-amber-700">← 生徒一覧</a>
<h1 class="text-2xl font-bold">新規生徒登録</h1>
<p class="text-sm text-stone-600">体験を経由せず入会登録できます。登録すると生徒アカウントと契約を作成し、入力したメールアドレスへ初回パスワード設定をご案内します。月2回は第1・第3週、月4回は第1〜4週が標準です。電子ドラムは一般料金を選択してください。</p>
<form data-staff-admission method="post" action="{{ route('staff.admission-applications.store') }}" class="space-y-6 rounded-3xl border border-stone-200 bg-white p-5 shadow-sm sm:p-8">@csrf
@if ($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="grid gap-5 sm:grid-cols-2">
<label class="grid gap-2 text-sm font-semibold">氏名<input name="name" required maxlength="100" value="{{ old('name') }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">フリガナ<input name="name_kana" required maxlength="100" value="{{ old('name_kana') }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">メール<input type="email" name="email" required value="{{ old('email') }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">電話番号<input name="phone" required value="{{ old('phone') }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">郵便番号（任意）<input name="postal_code" value="{{ old('postal_code') }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold sm:col-span-2">住所<input name="address" required value="{{ old('address') }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">コース<select name="course_id" required class="min-h-12 rounded-xl border border-stone-300 bg-white px-3">@foreach ($courses as $course)<option data-minutes="{{ $course->default_lesson_minutes }}" data-room="{{ str_contains($course->code, 'ELECTRONIC-') ? 'music_room' : 'studio34' }}" value="{{ $course->id }}" @selected(old('course_id', $defaultCourse?->id) == $course->id)>{{ $course->name }}</option>@endforeach</select></label>
<label class="grid gap-2 text-sm font-semibold">契約タイプ<select name="lesson_type" required class="min-h-12 rounded-xl border border-stone-300 bg-white px-3">@foreach (App\Enums\LessonType::cases() as $type)<option value="{{ $type->value }}" @selected(old('lesson_type', 'regular') === $type->value)>{{ $type->label() }}</option>@endforeach</select></label>
<label class="grid gap-2 text-sm font-semibold">料金区分<select name="pricing_category" required class="min-h-12 rounded-xl border border-stone-300 bg-white px-3">@foreach (App\Enums\PricingCategory::cases() as $category)<option value="{{ $category->value }}" @selected(old('pricing_category', 'standard') === $category->value)>{{ $category->label() }}</option>@endforeach</select></label>
<label class="grid gap-2 text-sm font-semibold">月回数<input type="number" name="monthly_lesson_count" min="1" max="4" required value="{{ old('monthly_lesson_count', $defaultCourse?->default_monthly_lessons ?? 2) }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">1回の時間（分）<input type="number" name="lesson_minutes" min="15" max="180" step="5" required value="{{ old('lesson_minutes', $defaultCourse?->default_lesson_minutes ?? 60) }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">会場<select name="venue_id" required class="min-h-12 rounded-xl border border-stone-300 bg-white px-3"><option value="">選択してください</option>@foreach ($venues as $venue)<option data-room="{{ $venue->termsRoomType() }}" value="{{ $venue->id }}" @selected(old('venue_id') == $venue->id)>{{ $venue->name }}</option>@endforeach</select></label>
<label class="grid gap-2 text-sm font-semibold">講師<select name="teacher_profile_id" required class="min-h-12 rounded-xl border border-stone-300 bg-white px-3"><option value="">選択してください</option>@foreach ($teachers as $teacher)<option value="{{ $teacher->id }}" @selected(old('teacher_profile_id') == $teacher->id)>{{ $teacher->display_name }}</option>@endforeach</select></label>
<label class="grid gap-2 text-sm font-semibold">希望開始日<input type="date" name="preferred_start_date" required min="{{ now()->format('Y-m-d') }}" value="{{ old('preferred_start_date', now()->addMonth()->startOfMonth()->format('Y-m-d')) }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
<label class="grid gap-2 text-sm font-semibold">固定曜日（レギュラー契約は必須）<select name="weekday" class="min-h-12 rounded-xl border border-stone-300 bg-white px-3"><option value="">選択</option>@foreach (['日','月','火','水','木','金','土'] as $value=>$label)<option value="{{ $value }}" @selected((string) old('weekday') === (string) $value)>{{ $label }}曜日</option>@endforeach</select></label>
<label class="grid gap-2 text-sm font-semibold">固定時間（レギュラー契約は必須）<input type="time" name="starts_at_time" value="{{ old('starts_at_time') }}" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
</div>
<label class="grid gap-2 text-sm font-semibold">備考（任意）<textarea name="notes" rows="4" maxlength="1000" class="rounded-xl border border-stone-300 p-3">{{ old('notes') }}</textarea></label><div class="absolute -left-[9999px]" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
<label class="flex items-start gap-3 rounded-xl bg-stone-50 p-4 text-sm"><input type="checkbox" name="privacy_accepted" value="1" required class="mt-1"><span><a href="{{ config('musako.privacy.policy_url') }}" target="_blank" class="font-bold underline">プライバシーポリシー</a>について本人の同意を確認しました。</span></label>
<button class="min-h-12 w-full rounded-xl bg-amber-400 px-6 font-bold">生徒を登録して案内メールを送る</button></form></div>
@endsection
