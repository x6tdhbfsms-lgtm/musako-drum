@extends('layouts.app')
@section('title', '空き枠カレンダー | MUSAKO')
@section('content')
<div><p class="text-sm font-semibold text-amber-700">LESSON CALENDAR</p><h1 class="text-2xl font-bold sm:text-3xl">空き枠カレンダー</h1><p class="mt-2 text-sm text-stone-600">希望する時間を選んで予約申請してください。</p></div>
@if ($errors->any())<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif
<div class="mt-5 flex flex-wrap items-center gap-3">
    <a href="{{ route('student.lesson-slots.index', ['date' => $range->previousFocus()->toDateString()]) }}" class="rounded-xl border bg-white px-4 py-3">前の週</a>
    <strong>{{ $range->start->format('Y年n月j日') }}〜{{ $range->end->format('n月j日') }}</strong>
    <a href="{{ route('student.lesson-slots.index', ['date' => $range->nextFocus()->toDateString()]) }}" class="rounded-xl border bg-white px-4 py-3">次の週</a>
    <form method="get"><label class="text-sm">日付を選択 <input type="date" name="date" value="{{ $range->focus->toDateString() }}" data-calendar-date class="min-h-11 rounded-xl border border-stone-300 px-3"></label><noscript><button>表示</button></noscript></form>
</div>
@foreach ($monthlySummaries as $key => $summary)
    @if ($summary && $summary->contracted > 0)<div class="mt-3 rounded-xl border border-stone-200 bg-white p-3 text-sm"><strong>{{ \Carbon\CarbonImmutable::parse($key.'-01')->format('n月') }}：{{ $summary->used }} / {{ $summary->contracted }}回</strong><span class="ml-2">残り{{ $summary->remaining }}回</span></div>@endif
@endforeach
<p class="mt-5 text-sm text-stone-600">週ごとに12:00〜21:00の枠を開始時刻順に表示します。土日は20:00までです。スマートフォンでは表を横にスクロールできます。</p>
<div class="mt-3 overflow-x-auto rounded-2xl border border-stone-200 bg-white" role="region" aria-label="週間空き枠カレンダー" tabindex="0">
<table class="w-full min-w-[1000px] table-fixed border-collapse text-sm">
    <thead><tr class="bg-stone-50"><th class="sticky left-0 z-10 w-20 bg-stone-50 p-3">時間</th>@foreach ($calendarDays as $day)<th class="border-l border-stone-200 p-3 {{ $day->isToday() ? 'bg-amber-100' : '' }}">{{ $day->format('n/j') }}（{{ ['日','月','火','水','木','金','土'][$day->dayOfWeek] }}）</th>@endforeach</tr></thead>
    <tbody>
    @foreach (range(12, 20) as $hour)
        <tr><th scope="row" class="sticky left-0 z-10 border-t border-stone-200 bg-stone-50 p-3 align-top">{{ $hour }}:00</th>
        @foreach ($calendarDays as $day)
            @php
                $slots = $lessonSlotsByDay->get($day->format('Y-m-d'), collect())->filter(fn ($slot) => $slot->starts_at->hour === $hour);
                $summary = $monthlySummaries->get($day->format('Y-m'));
            @endphp
            <td class="border-l border-t border-stone-200 p-2 align-top">
                <div class="grid min-h-16 gap-2">
                @forelse ($slots as $slot)
                    <form method="post" action="{{ route('student.reservations.store', $slot) }}" class="rounded-xl border border-amber-200 bg-amber-50 p-2 text-xs">
                        @csrf
                        <strong class="block text-sm">{{ $slot->starts_at->format('H:i') }}〜{{ $slot->ends_at->format('H:i') }}</strong>
                        <p class="mt-1 break-words">{{ $slot->venue?->name ?? '会場未定' }}</p>
                        <p class="mt-1">{{ $slot->course?->name }}</p>
                        <p class="mt-1 text-stone-600">{{ $slot->teacherProfile->display_name }}</p>
                        @if ($requestedSlotIds->contains($slot->id))<p class="mt-2 font-semibold">申請済み</p>
                        @elseif ($slot->approved_reservations_count + $slot->approved_trial_requests_count >= $slot->capacity)<p class="mt-2 font-semibold">満席</p>
                        @elseif ($summary && $summary->contracted > 0 && $summary->remaining === 0)<p class="mt-2 font-semibold">上限到達</p>
                        @else
                            <details class="mt-2"><summary>先生へのメモ（任意）</summary><input name="student_note" aria-label="先生へのメモ" maxlength="1000" class="mt-2 min-h-11 w-full min-w-0 rounded-lg border border-stone-300 px-2"></details>
                            <button class="mt-2 min-h-11 w-full rounded-lg bg-stone-900 px-2 font-semibold text-white">予約申請</button>
                        @endif
                    </form>
                @empty
                    <span class="py-3 text-center text-xs text-stone-400">{{ $day->isWeekend() && $hour >= 20 ? '営業時間外' : '—' }}</span>
                @endforelse
                </div>
            </td>
        @endforeach
        </tr>
    @endforeach
    <tr><th class="sticky left-0 border-t bg-stone-50 p-3">21:00</th><td colspan="7" class="border-t p-3 text-xs text-stone-500">表示終了</td></tr>
    </tbody>
</table>
</div>
@endsection
