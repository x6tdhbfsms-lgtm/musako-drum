@extends('layouts.app')

@section('title', 'レギュラースケジュール管理 | MUSAKO')

@section('content')
@php
    $weekdays = ['日','月','火','水','木','金','土'];
    $statusLabels = ['draft' => '下書き', 'conflict' => '競合', 'confirmed' => '確定済み', 'skipped' => 'スキップ', 'cancelled' => '取消'];
    $statusClasses = ['draft' => 'bg-amber-100 text-amber-800', 'conflict' => 'bg-red-100 text-red-700', 'confirmed' => 'bg-emerald-100 text-emerald-800', 'skipped' => 'bg-stone-200 text-stone-700', 'cancelled' => 'bg-stone-200 text-stone-700'];
    $draftCount = $batches->sum(fn ($batch) => $batch->occurrences->where('status.value', 'draft')->count());
    $conflictCount = $batches->sum(fn ($batch) => $batch->occurrences->where('status.value', 'conflict')->count());
    $confirmedCount = $batches->sum(fn ($batch) => $batch->occurrences->where('status.value', 'confirmed')->count());
    $confirmable = $batches->flatMap->occurrences->filter(fn ($occurrence) => $occurrence->status->value === 'draft');
@endphp

<div class="flex flex-wrap items-end justify-between gap-4">
    <div><p class="text-xs font-bold tracking-widest text-amber-700">REGULAR SCHEDULE</p><h1 class="text-2xl font-bold sm:text-3xl">レギュラースケジュール管理</h1><p class="mt-2 text-sm text-stone-600">候補を下書き生成し、競合を確認してから生徒の予約へ確定します。</p></div>
    <form method="get" class="flex items-end gap-2"><label class="grid gap-1 text-sm font-semibold">対象月<input type="month" name="month" value="{{ $month->format('Y-m') }}" class="min-h-11 rounded-xl border border-stone-300 px-3"></label><button class="min-h-11 rounded-xl bg-stone-900 px-4 font-bold text-white">表示</button></form>
</div>

<section class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-2xl bg-amber-50 p-4"><span class="text-xs text-stone-500">下書き</span><strong class="block text-2xl">{{ $draftCount }}</strong></div>
    <div class="rounded-2xl bg-red-50 p-4"><span class="text-xs text-stone-500">競合あり</span><strong class="block text-2xl text-red-700">{{ $conflictCount }}</strong></div>
    <div class="rounded-2xl bg-stone-100 p-4"><span class="text-xs text-stone-500">未生成契約</span><strong class="block text-2xl">{{ $unGeneratedCount }}</strong></div>
    <div class="rounded-2xl bg-emerald-50 p-4"><span class="text-xs text-stone-500">確定済み</span><strong class="block text-2xl text-emerald-800">{{ $confirmedCount }}</strong></div>
</section>

<section class="mt-6 flex flex-wrap gap-3 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
    <form id="selected-confirm" method="post" action="{{ route('staff.regular-schedules.confirm') }}">@csrf</form>
    <form method="post" action="{{ route('staff.regular-schedules.generate') }}">@csrf<input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><button class="min-h-11 rounded-xl bg-amber-400 px-5 font-bold">{{ $month->format('Y年n月') }}分を生成／差分更新</button></form>
    @if ($confirmable->isNotEmpty())<button form="selected-confirm" class="min-h-11 rounded-xl border border-emerald-700 px-5 font-bold text-emerald-800">選択した予定を確定</button>@endif
    @if ($confirmable->isNotEmpty())
        <form method="post" action="{{ route('staff.regular-schedules.confirm') }}">@csrf<input type="hidden" name="skip_conflicts" value="1">@foreach ($confirmable as $item)<input type="hidden" name="occurrence_ids[]" value="{{ $item->id }}">@endforeach<button class="min-h-11 rounded-xl bg-emerald-700 px-5 font-bold text-white">競合なしを一括確定</button></form>
    @endif
</section>

@if (auth()->user()->role->value === 'admin')
<details class="mt-4 rounded-2xl border border-stone-200 bg-white p-4"><summary class="cursor-pointer font-bold">自動生成設定</summary><form method="post" action="{{ route('staff.regular-schedules.settings.update') }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf @method('patch')<label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="automatic_generation_enabled" value="1" @checked($settings->automatic_generation_enabled)> 自動生成を有効にする</label><label class="grid gap-1 text-sm font-semibold">毎月の生成日<input type="number" name="generation_day" min="1" max="28" value="{{ $settings->generation_day }}" class="min-h-11 w-24 rounded-xl border border-stone-300 px-3"></label><button class="min-h-11 rounded-xl border border-stone-300 px-4 font-bold">保存</button></form></details>
@endif

<div class="mt-8 grid gap-5">
@forelse ($batches as $batch)
    @php($enrollment = $batch->lessonEnrollment)
    <article class="min-w-0 rounded-3xl border border-stone-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-xl font-bold">{{ $enrollment->studentProfile->user->name }}</h2><p class="mt-1 text-sm text-stone-600">月{{ $batch->expected_count }}回・{{ $weekdays[$enrollment->weekday] }}曜日 {{ mb_substr($enrollment->starts_at_time, 0, 5) }}・{{ $enrollment->teacherProfile?->display_name }}・{{ $enrollment->venue?->name ?? '会場未定' }}</p></div><span class="rounded-full px-3 py-1 text-xs font-bold {{ $statusClasses[$batch->status->value] ?? 'bg-stone-100' }}">{{ $statusLabels[$batch->status->value] ?? $batch->status->value }}</span></div>

        <form method="post" action="{{ route('staff.regular-schedules.pattern.update', $enrollment) }}" class="mt-4 flex flex-wrap items-center gap-3 rounded-2xl bg-stone-50 p-3">@csrf @method('patch')<span class="text-sm font-bold">利用週</span>@for ($week = 1; $week <= 5; $week++)<label class="flex min-h-9 items-center gap-1 text-sm"><input type="checkbox" name="regular_week_numbers[]" value="{{ $week }}" @checked(in_array($week, $enrollment->regular_week_numbers ?? range(1, (int) $enrollment->monthly_lesson_limit), true))>第{{ $week }}</label>@endfor<button class="min-h-9 rounded-lg border border-stone-300 bg-white px-3 text-sm font-bold">週設定を保存</button></form>
        @error('regular_week_numbers')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        @if ($batch->warning)<p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">{{ $batch->warning }}</p>@endif

        <div class="mt-4 grid gap-3">
        @foreach ($batch->occurrences->sortBy('starts_at') as $occurrence)
            <div class="min-w-0 rounded-2xl border {{ $occurrence->status->value === 'conflict' ? 'border-red-300 bg-red-50' : 'border-stone-200' }} p-4">
                <div class="flex flex-wrap items-center justify-between gap-2"><div class="flex min-w-0 items-center gap-2">@if ($occurrence->status->value === 'draft')<input form="selected-confirm" type="checkbox" name="occurrence_ids[]" value="{{ $occurrence->id }}" class="size-5 shrink-0" aria-label="{{ $occurrence->starts_at->format('n/j H:i') }}を選択">@endif<strong>{{ $occurrence->starts_at->format('n/j') }}（{{ $weekdays[$occurrence->starts_at->dayOfWeek] }}） {{ $occurrence->starts_at->format('H:i') }}〜{{ $occurrence->ends_at->format('H:i') }}</strong></div><span class="rounded-full px-3 py-1 text-xs font-bold {{ $statusClasses[$occurrence->status->value] ?? 'bg-stone-100' }}">{{ $statusLabels[$occurrence->status->value] ?? $occurrence->status->value }}</span></div>
                @if ($occurrence->conflict_reasons)<ul class="mt-2 list-disc pl-5 text-sm text-red-700">@foreach ($occurrence->conflict_reasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>@endif
                @if (in_array($occurrence->status->value, ['draft','conflict'], true))
                <form method="post" action="{{ route('staff.regular-schedules.occurrences.update', $occurrence) }}" class="mt-3 grid gap-3 sm:grid-cols-4">@csrf @method('patch')<label class="grid gap-1 text-xs font-semibold sm:col-span-2">日時<input type="datetime-local" name="starts_at" value="{{ $occurrence->starts_at->format('Y-m-d\TH:i') }}" class="min-h-11 min-w-0 rounded-xl border border-stone-300 px-3"></label><label class="grid gap-1 text-xs font-semibold">講師<select name="teacher_profile_id" class="min-h-11 min-w-0 rounded-xl border border-stone-300 bg-white px-3" @disabled(auth()->user()->role->value === 'teacher')>@foreach ($teachers as $teacher)<option value="{{ $teacher->id }}" @selected($teacher->id === $occurrence->teacher_profile_id)>{{ $teacher->display_name }}</option>@endforeach</select>@if(auth()->user()->role->value === 'teacher')<input type="hidden" name="teacher_profile_id" value="{{ $occurrence->teacher_profile_id }}">@endif</label><label class="grid gap-1 text-xs font-semibold">会場<select name="venue_id" class="min-h-11 min-w-0 rounded-xl border border-stone-300 bg-white px-3"><option value="">未定</option>@foreach ($venues as $venue)<option value="{{ $venue->id }}" @selected($venue->id === $occurrence->venue_id)>{{ $venue->name }}</option>@endforeach</select></label><button class="min-h-11 rounded-xl border border-stone-300 font-bold sm:col-span-4">下書きを更新</button></form>
                <form method="post" action="{{ route('staff.regular-schedules.confirm') }}" class="mt-2">@csrf<input type="hidden" name="occurrence_ids[]" value="{{ $occurrence->id }}"><button class="min-h-11 w-full rounded-xl bg-emerald-700 px-4 font-bold text-white disabled:bg-stone-300" @disabled($occurrence->status->value === 'conflict')>この予定を確定</button></form>
                <form method="post" action="{{ route('staff.regular-schedules.occurrences.status', $occurrence) }}" class="mt-2">@csrf @method('patch')<input type="hidden" name="status" value="skipped"><button class="min-h-11 w-full rounded-xl border border-stone-300 px-4 font-bold text-stone-700">この候補をスキップ</button></form>
                @elseif ($occurrence->status->value === 'confirmed')<p class="mt-2 text-sm text-stone-500">確定者：{{ $occurrence->confirmed_by_user_id ? 'スタッフ #'.$occurrence->confirmed_by_user_id : '—' }}／{{ $occurrence->confirmed_at?->format('Y/n/j H:i') }}</p><form method="post" action="{{ route('staff.regular-schedules.occurrences.status', $occurrence) }}" class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto]">@csrf @method('patch')<input type="hidden" name="status" value="cancelled"><input name="reason" required maxlength="500" placeholder="取消理由" class="min-h-11 min-w-0 rounded-xl border border-stone-300 px-3"><button class="min-h-11 rounded-xl border border-red-300 px-4 font-bold text-red-700">予定を取り消す</button></form>@endif
            </div>
        @endforeach
        </div>
    </article>
@empty
    <div class="rounded-3xl border border-dashed border-stone-300 bg-white p-10 text-center text-stone-500">この月はまだ生成されていません。「生成／差分更新」を実行してください。</div>
@endforelse
</div>
@endsection
