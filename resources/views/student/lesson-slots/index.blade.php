@extends('layouts.app')
@section('title', '空き枠カレンダー | MUSAKO')
@section('content')
<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div><p class="text-sm font-semibold text-amber-700">LESSON CALENDAR</p><h1 class="text-2xl font-bold sm:text-3xl">空き枠カレンダー</h1><p class="mt-2 text-sm text-stone-600">希望する時間を選んで予約申請してください。</p></div>
    <div class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white p-1"><a href="{{ route('student.lesson-slots.index', ['month' => $month->subMonth()->format('Y-m')]) }}" class="grid min-h-10 min-w-10 place-items-center rounded-lg hover:bg-stone-100" aria-label="前月">←</a><strong class="min-w-28 text-center">{{ $month->format('Y年 n月') }}</strong><a href="{{ route('student.lesson-slots.index', ['month' => $month->addMonth()->format('Y-m')]) }}" class="grid min-h-10 min-w-10 place-items-center rounded-lg hover:bg-stone-100" aria-label="翌月">→</a></div>
</div>
@if ($errors->any())<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif
@if ($monthlySummary && $monthlySummary->contracted > 0)<div class="mt-5 rounded-xl border border-stone-200 bg-white p-4 text-sm"><strong>{{ $month->format('n月') }}：{{ $monthlySummary->used }} / {{ $monthlySummary->contracted }}回</strong><span class="ml-2 text-stone-600">残り{{ $monthlySummary->remaining }}回</span>@if ($monthlySummary->remaining === 0)<p class="mt-2 font-semibold text-amber-800">今月の予約可能回数を使い切っています。</p>@endif</div>@endif

<div class="mt-6 hidden overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm md:block">
    <div class="grid grid-cols-7 border-b border-stone-200 bg-stone-50 text-center text-xs font-semibold text-stone-500">@foreach (['日','月','火','水','木','金','土'] as $day)<div class="py-3">{{ $day }}</div>@endforeach</div>
    <div class="grid grid-cols-7">
        @foreach ($calendarDays as $day)
            @php($daySlots = $lessonSlotsByDay->get($day->format('Y-m-d'), collect()))
            <div class="min-h-32 border-b border-r border-stone-100 p-2 {{ $day->month !== $month->month ? 'bg-stone-50 text-stone-400' : '' }}"><span class="text-xs font-semibold">{{ $day->day }}</span><div class="mt-2 grid gap-2">
                @foreach ($daySlots as $slot)
                    <form method="post" action="{{ route('student.reservations.store', $slot) }}" class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs">@csrf<strong class="block">{{ $slot->starts_at->format('H:i') }}</strong><span class="block truncate text-stone-600">{{ $slot->teacherProfile->display_name }}</span>
                        @if ($requestedSlotIds->contains($slot->id))<span class="mt-1 block font-semibold text-stone-500">申請済み</span>
                        @elseif ($slot->approved_reservations_count >= $slot->capacity)<span class="mt-1 block font-semibold text-red-600">満席</span>
                        @elseif ($monthlySummary && $monthlySummary->contracted > 0 && $monthlySummary->remaining === 0)<span class="mt-1 block font-semibold text-amber-800">上限到達</span>
                        @else<button class="mt-2 w-full rounded-md bg-stone-900 px-2 py-2 font-semibold text-white">申請</button>@endif
                    </form>
                @endforeach
            </div></div>
        @endforeach
    </div>
</div>

<div class="mt-6 grid gap-4 md:hidden">
    @forelse ($lessonSlotsByDay as $daySlots)
        <section><h2 class="mb-2 text-sm font-bold text-stone-600">{{ $daySlots->first()->starts_at->format('n月j日') }}（{{ ['日','月','火','水','木','金','土'][$daySlots->first()->starts_at->dayOfWeek] }}）</h2><div class="grid gap-3">
            @foreach ($daySlots as $slot)
                <article class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><div class="flex items-start justify-between gap-3"><div><strong class="text-lg">{{ $slot->starts_at->format('H:i') }}〜{{ $slot->ends_at->format('H:i') }}</strong><p class="mt-1 text-sm text-stone-600">{{ $slot->teacherProfile->display_name }} · {{ $slot->course?->name ?? 'コース指定なし' }}</p><p class="mt-1 text-xs text-stone-500">{{ $slot->venue?->name ?? '会場未定' }} · 残り{{ max(0, $slot->capacity - $slot->approved_reservations_count) }}名</p></div></div>
                    @if ($requestedSlotIds->contains($slot->id))<div class="mt-4 rounded-xl bg-stone-100 py-3 text-center text-sm font-semibold text-stone-600">申請済み</div>
                    @elseif ($slot->approved_reservations_count >= $slot->capacity)<div class="mt-4 rounded-xl bg-red-50 py-3 text-center text-sm font-semibold text-red-700">満席</div>
                    @elseif ($monthlySummary && $monthlySummary->contracted > 0 && $monthlySummary->remaining === 0)<div class="mt-4 rounded-xl bg-amber-50 py-3 text-center text-sm font-semibold text-amber-800">今月の予約上限に達しています</div>
                    @else<form method="post" action="{{ route('student.reservations.store', $slot) }}" class="mt-4">@csrf<label class="grid gap-2 text-xs text-stone-500">先生へのメモ（任意）<input name="student_note" maxlength="1000" class="min-h-11 rounded-xl border border-stone-300 px-3 text-sm text-stone-900"></label><button class="mt-3 min-h-12 w-full rounded-xl bg-amber-400 font-bold text-stone-900">この枠を予約申請</button></form>@endif
                </article>
            @endforeach
        </div></section>
    @empty
        <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-10 text-center text-stone-500">この月に予約可能な枠はありません。</div>
    @endforelse
</div>
@endsection
