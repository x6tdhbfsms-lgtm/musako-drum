@extends('layouts.app')
@section('title', 'レッスンカレンダー | MUSAKO')
@section('content')
<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div><p class="text-sm font-semibold tracking-widest text-amber-700">MY CALENDAR</p><h1 class="mt-1 text-2xl font-bold sm:text-4xl">こんにちは、{{ auth()->user()->name }}さん</h1><p class="mt-2 text-sm text-stone-600">色で予定を確認し、空き枠からそのまま予約できます。</p></div>
    <div class="flex items-center justify-between gap-2 rounded-xl border border-stone-200 bg-white p-1 shadow-sm"><a href="{{ route('student.dashboard', ['month' => $month->subMonth()->format('Y-m')]) }}" class="grid min-h-11 min-w-11 place-items-center rounded-lg hover:bg-stone-100" aria-label="前月">←</a><strong class="min-w-28 text-center">{{ $month->format('Y年 n月') }}</strong><a href="{{ route('student.dashboard', ['month' => $month->addMonth()->format('Y-m')]) }}" class="grid min-h-11 min-w-11 place-items-center rounded-lg hover:bg-stone-100" aria-label="翌月">→</a></div>
</div>
@if ($errors->any())<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif

<section class="mt-6" aria-labelledby="lesson-calendar-title">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><h2 id="lesson-calendar-title" class="text-xl font-bold">レッスンカレンダー</h2><a href="{{ route('student.reservations.index') }}" class="text-sm font-semibold text-amber-700">予約一覧を見る →</a></div>
    <div class="mt-3 flex flex-wrap gap-2 text-xs">
        @foreach ([['予約可能','bg-amber-100 text-amber-900'], ['予約申請中','bg-sky-100 text-sky-800'], ['予約確定','bg-emerald-100 text-emerald-800'], ['振替申請中','bg-violet-100 text-violet-800'], ['振替確定','bg-indigo-100 text-indigo-800'], ['お休み連絡済み','bg-red-100 text-red-700'], ['遅刻連絡済み','bg-orange-100 text-orange-800'], ['満席','bg-stone-200 text-stone-700'], ['休講／予約不可','bg-stone-100 text-stone-500']] as [$label, $classes])<span class="rounded-full px-3 py-1 font-semibold {{ $classes }}">{{ $label }}</span>@endforeach
    </div>

    <div class="mt-4 hidden overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm md:block">
        <div class="grid grid-cols-7 border-b border-stone-200 bg-stone-50 text-center text-xs font-semibold text-stone-500">@foreach (['日','月','火','水','木','金','土'] as $dayName)<div class="py-3">{{ $dayName }}</div>@endforeach</div>
        <div class="grid grid-cols-7">
            @foreach ($calendarDays as $day)
                @php
                    $entries = $calendarEntriesByDay->get($day->format('Y-m-d'), collect());
                @endphp
                <section class="min-h-36 min-w-0 border-b border-r border-stone-100 p-2 {{ $day->month !== $month->month ? 'bg-stone-50 text-stone-400' : '' }}"><div class="flex items-center justify-between"><span class="text-xs font-bold {{ $day->isToday() ? 'grid size-7 place-items-center rounded-full bg-stone-900 text-white' : '' }}">{{ $day->day }}</span><a href="{{ route('student.dashboard', ['month' => $month->format('Y-m'), 'date' => $day->format('Y-m-d')]) }}" class="text-[10px] text-stone-400">一覧</a></div><div class="mt-2 grid gap-1.5">@foreach ($entries as $entry) @include('student.calendar._entry', ['entry' => $entry, 'compact' => true]) @endforeach</div></section>
            @endforeach
        </div>
    </div>

    <div class="mt-4 md:hidden">
        <div class="rounded-2xl border border-stone-200 bg-white p-3 shadow-sm"><div class="grid grid-cols-7 text-center text-[11px] font-semibold text-stone-400">@foreach (['日','月','火','水','木','金','土'] as $dayName)<span class="py-1">{{ $dayName }}</span>@endforeach</div><div class="mt-1 grid grid-cols-7 gap-1">@foreach ($calendarDays as $day) @php $hasEntries = $calendarEntriesByDay->has($day->format('Y-m-d')); @endphp <a href="{{ route('student.dashboard', ['month' => $month->format('Y-m'), 'date' => $day->format('Y-m-d')]) }}" class="relative grid min-h-11 place-items-center rounded-xl text-sm font-semibold {{ $selectedDate->isSameDay($day) ? 'bg-stone-900 text-white' : ($day->month === $month->month ? 'hover:bg-stone-100' : 'text-stone-300') }}">{{ $day->day }}@if ($hasEntries)<span class="absolute bottom-1 size-1 rounded-full {{ $selectedDate->isSameDay($day) ? 'bg-amber-300' : 'bg-amber-500' }}"></span>@endif</a> @endforeach</div></div>
        <div class="mt-4"><h3 class="text-lg font-bold">{{ $selectedDate->format('n月j日') }}（{{ ['日','月','火','水','木','金','土'][$selectedDate->dayOfWeek] }}）</h3><div class="mt-3 grid gap-3">@forelse ($calendarEntriesByDay->get($selectedDate->format('Y-m-d'), collect()) as $entry) @include('student.calendar._entry', ['entry' => $entry, 'compact' => false]) @empty <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-8 text-center text-sm text-stone-500">この日のレッスン枠はありません。</div> @endforelse</div></div>
    </div>
</section>

<div class="mt-8 grid gap-4 sm:grid-cols-3"><a href="{{ route('student.reservations.index') }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><span class="text-sm text-stone-500">承認待ち</span><strong class="mt-2 block text-3xl">{{ $pendingCount }}</strong></a><a href="{{ route('student.reservations.index') }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><span class="text-sm text-stone-500">確定予約</span><strong class="mt-2 block text-3xl">{{ $approvedCount }}</strong></a><div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><span class="text-sm text-stone-500">次回レッスン</span>@if ($nextReservation)<strong class="mt-2 block text-lg">{{ $nextReservation->lessonSlot->starts_at->format('n/j H:i') }}</strong><p class="mt-1 text-sm text-stone-600">{{ $nextReservation->lessonSlot->teacherProfile->display_name }} · {{ $nextReservation->lessonSlot->venue?->name ?? '会場未定' }}</p>@else<p class="mt-3 text-sm text-stone-500">確定した予定はありません</p>@endif</div></div>
@php
    $procedures = [
        ['時間割／曜日／時間変更', '曜日と開始時間', route('student.contract-change-requests.index', ['type' => 'schedule'])],
        ['月のレッスン回数変更', '月2回・月4回など', route('student.contract-change-requests.index', ['type' => 'monthly_lessons'])],
        ['レッスン時間変更', '1回あたりの時間', route('student.contract-change-requests.index', ['type' => 'lesson_minutes'])],
        ['コース変更', '現在のコースを変更', route('student.contract-change-requests.index', ['type' => 'course_change'])],
        ['コース追加', '新しいコースを追加', route('student.contract-change-requests.index', ['type' => 'course_add'])],
        ['会場変更', '登録済み会場から選択', route('student.contract-change-requests.index', ['type' => 'venue_change'])],
        ['個人情報変更', '氏名・連絡先・住所', route('student.personal-information-change-requests.index')],
        ['支払い方法変更', '決済情報は保存しません', route('student.payment-method-change-requests.index')],
        ['その他問い合わせ', '教室へのお問い合わせ', route('student.inquiries.index')],
        ['お休み・遅刻', '確定予約の連絡', route('student.attendance-notices.index')],
        ['振替', '申請履歴を確認', route('student.transfer-requests.index')],
        ['休会', '在籍に関する申請', route('student.membership-status-requests.index', ['type' => 'pause']).'#application-form'],
        ['退会', '在籍に関する申請', route('student.membership-status-requests.index', ['type' => 'withdraw']).'#application-form'],
        ['再開', '在籍に関する申請', route('student.membership-status-requests.index', ['type' => 'resume']).'#application-form'],
    ];
@endphp
<section class="mt-9" aria-labelledby="procedures-title"><div><p class="text-sm font-semibold tracking-widest text-amber-700">PROCEDURES</p><h2 id="procedures-title" class="mt-1 text-2xl font-bold">各種お手続き</h2><p class="mt-2 text-sm text-stone-600">契約内容の変更や教室への連絡はこちらから申請できます。</p></div><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach ($procedures as [$title, $description, $url])<a href="{{ $url }}" class="group min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm transition hover:border-amber-300"><span class="flex items-center justify-between gap-3 font-semibold"><span>{{ $title }}</span><span class="text-amber-600 group-hover:translate-x-0.5">→</span></span><span class="mt-1 block text-xs font-normal text-stone-500">{{ $description }}</span></a>@endforeach</div></section>
@endsection
