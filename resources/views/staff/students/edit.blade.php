@extends('layouts.app')
@section('title', '生徒情報を編集 | MUSAKO')
@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <a href="{{ route('staff.students.show', $student) }}" class="text-sm font-bold text-amber-700">← 生徒詳細へ戻る</a>
    <h1 class="text-2xl font-bold">生徒情報を編集</h1>
    <p class="break-words text-stone-600">{{ $student->user->name }}さん</p>
    <form method="post" action="{{ route('staff.students.update', $student) }}" class="space-y-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7">
        @csrf @method('PATCH')
        <input type="hidden" name="revision" value="{{ old('revision', $revision) }}">
        <h2 class="text-xl font-bold">基本情報</h2>
        <p class="text-sm text-stone-600">ログイン名を変更した場合は生徒へお伝えください。パスワード欄を空欄にすると現在のパスワードを保持します。</p>
        @if ($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="grid gap-5 sm:grid-cols-2">
            @foreach (['name' => ['氏名', $student->user->name, 'text', 100], 'login_name' => ['ログイン名', $student->user->login_name, 'text', 64], 'phone' => ['電話番号', $student->phone, 'tel', 30], 'postal_code' => ['郵便番号', $student->postal_code, 'text', 12], 'address' => ['住所', $student->address, 'text', 255]] as $field => [$label, $value, $type, $max])
            <label class="grid min-w-0 gap-2 text-sm font-semibold {{ $field === 'address' ? 'sm:col-span-2' : '' }}">{{ $label }}<input name="{{ $field }}" type="{{ $type }}" value="{{ old($field, $value) }}" maxlength="{{ $max }}" @required(in_array($field, ['name', 'login_name'])) class="min-h-12 min-w-0 w-full rounded-xl border border-stone-300 px-3"></label>
            @endforeach
<label class="grid gap-2 text-sm font-semibold">新しいパスワード（変更時のみ）<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" class="min-h-12 rounded-xl border border-stone-300 px-3"><span class="text-xs font-normal text-stone-500">12文字以上</span></label>
<label class="grid gap-2 text-sm font-semibold">パスワード（確認）<input type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" class="min-h-12 rounded-xl border border-stone-300 px-3"></label>
        </div>
        <button class="min-h-12 w-full rounded-xl bg-amber-400 px-5 font-bold">基本情報を保存</button>
    </form>
    <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7">
        <h2 class="text-xl font-bold">契約情報</h2>
        <p class="text-sm text-stone-600">変更する契約を選択してください。適用日を指定し、変更前の履歴を残して更新します。</p>
        @foreach ($student->enrollments as $enrollment)
            @if ($enrollment->status === App\Enums\EnrollmentStatus::Active && $enrollment->replacements->isEmpty() && ($enrollment->ends_on === null || $enrollment->ends_on->gte(today())))
            <a href="{{ route('staff.students.enrollments.edit', [$student, $enrollment]) }}" class="flex min-h-12 flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-300 p-4"><span>{{ $enrollment->course->name }} · 月{{ $enrollment->monthly_lesson_limit }}回<br><span class="text-sm text-stone-500">{{ $enrollment->starts_on->format('Y/m/d') }}から</span></span><strong>契約を編集 →</strong></a>
            @endif
        @endforeach
    </section>
</div>
@endsection
