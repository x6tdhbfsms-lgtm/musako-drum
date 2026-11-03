@extends('layouts.app')
@section('title', '予約詳細 | MUSAKO')
@section('content')
@php
    $slot = $reservationRequest->lessonSlot;
    $statusLabel = ['pending' => '予約申請中', 'approved' => '予約確定', 'rejected' => '却下', 'cancelled' => 'キャンセル済み'][$reservationRequest->status->value];
    $statusClass = ['pending' => 'bg-amber-100 text-amber-900', 'approved' => 'bg-emerald-100 text-emerald-900', 'rejected' => 'bg-red-100 text-red-800', 'cancelled' => 'bg-stone-200 text-stone-600'][$reservationRequest->status->value];
@endphp
<div class="mx-auto max-w-4xl"><a href="{{ route('student.dashboard', ['month' => $slot->starts_at->format('Y-m'), 'date' => $slot->starts_at->format('Y-m-d')]) }}" class="text-sm font-semibold text-amber-700">← カレンダーに戻る</a><div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold tracking-widest text-amber-700">RESERVATION DETAIL</p><h1 class="mt-1 text-2xl font-bold sm:text-3xl">予約詳細</h1></div><span class="self-start rounded-full px-3 py-1 text-sm font-bold {{ $statusClass }}">{{ $statusLabel }}</span></div>
@if ($errors->any())<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif
<section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7"><strong class="text-xl sm:text-2xl">{{ $slot->starts_at->format('Y年n月j日 H:i') }}〜{{ $slot->ends_at->format('H:i') }}</strong><dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-stone-500">会場</dt><dd class="mt-1 font-semibold">{{ $slot->venue?->name ?? '会場未定' }}</dd></div><div><dt class="text-stone-500">コース</dt><dd class="mt-1 font-semibold">{{ $slot->course?->name ?? 'コース指定なし' }}</dd></div><div><dt class="text-stone-500">予約状態</dt><dd class="mt-1 font-semibold">{{ $statusLabel }}</dd></div></dl>@if ($reservationRequest->staff_note)<p class="mt-5 rounded-xl bg-stone-50 p-3 text-sm">先生から：{{ $reservationRequest->staff_note }}</p>@endif</section>

@if ($latestHistory)
    <section class="mt-5 rounded-2xl border-2 {{ $latestHistory['type'] === 'transfer' ? 'border-violet-300 bg-violet-50' : 'border-orange-200 bg-orange-50' }} p-5 shadow-sm sm:p-6" aria-label="最新の連絡履歴">
        <p class="text-xs font-bold tracking-wider text-stone-500">最新の履歴</p>
        <h2 class="mt-1 text-xl font-bold {{ $latestHistory['type'] === 'transfer' ? 'text-violet-950' : 'text-orange-950' }}">{{ $latestHistory['title'] }}</h2>
        @if ($latestHistory['summary'])<p class="mt-2 text-sm font-semibold">{{ $latestHistory['summary'] }}</p>@endif
        <p class="mt-2 text-sm text-stone-600">{{ $latestHistory['type'] === 'transfer' ? '申請・処理日時' : '連絡・更新日時' }}：{{ $latestHistory['occurred_at']->timezone('Asia/Tokyo')->format('Y年n月j日 H:i') }}</p>
        @if ($latestHistory['type'] === 'attendance' && $latestHistory['updated_at'] && ! $latestHistory['updated_at']->equalTo($latestHistory['created_at']))
            <p class="mt-1 text-xs text-stone-500">初回連絡日時：{{ $latestHistory['created_at']->timezone('Asia/Tokyo')->format('Y年n月j日 H:i') }}</p>
        @endif
    </section>
@endif

@if ($reservationRequest->status->value === 'approved' && $slot->ends_at->isFuture())
    @if (now()->lessThanOrEqualTo($reservationRequest->transferRequestDeadline()))
        <section class="mt-6 rounded-2xl border-2 border-violet-300 bg-violet-50 p-5 shadow-sm sm:p-6">
            <h2 class="text-xl font-bold text-violet-950">レッスンを振替</h2>
            <p class="mt-1 text-sm text-violet-900">別の日程への変更を希望する場合はこちらから申請してください。</p>
            <a href="{{ route('student.transfer-requests.create', $reservationRequest) }}" class="mt-4 inline-flex min-h-14 w-full items-center justify-center rounded-xl bg-violet-700 px-5 text-lg font-bold text-white shadow-sm hover:bg-violet-800">振替を申請する →</a>
        </section>
    @endif

    <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="text-xl font-bold">お休み・遅刻の連絡</h2>
        <p class="mt-1 text-sm text-stone-500">該当するボタンを押してください。遅刻時間の入力は不要です。</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <form method="post" action="{{ route('student.attendance-notices.store', $reservationRequest) }}">@csrf @method('put')<input type="hidden" name="type" value="absence"><button class="min-h-14 w-full rounded-xl bg-red-600 px-5 text-lg font-bold text-white hover:bg-red-700">お休みを連絡する</button></form>
            <form method="post" action="{{ route('student.attendance-notices.store', $reservationRequest) }}">@csrf @method('put')<input type="hidden" name="type" value="late"><button class="min-h-14 w-full rounded-xl bg-orange-500 px-5 text-lg font-bold text-white hover:bg-orange-600">遅刻を連絡する</button></form>
        </div>
        @if ($reservationRequest->attendanceNotice)<p class="mt-3 text-sm font-semibold text-stone-600">連絡済みです。ボタンを押すと連絡内容を変更できます。</p>@endif
    </section>
@endif

@if ($previousHistory->isNotEmpty())
    <details class="mt-6 rounded-2xl border border-stone-200 bg-stone-50 p-4">
        <summary class="cursor-pointer font-semibold text-stone-700">過去の連絡・振替履歴（{{ $previousHistory->count() }}件）</summary>
        <ol class="mt-4 grid gap-3">
            @foreach ($previousHistory as $historyItem)
                <li class="rounded-xl border border-stone-200 bg-white p-4">
                    <p class="font-semibold">{{ $historyItem['title'] }}</p>
                    @if ($historyItem['summary'])<p class="mt-1 text-sm text-stone-600">{{ $historyItem['summary'] }}</p>@endif
                    <p class="mt-1 text-xs text-stone-500">{{ $historyItem['occurred_at']->timezone('Asia/Tokyo')->format('Y年n月j日 H:i') }}</p>
                </li>
            @endforeach
        </ol>
    </details>
@endif
</div>
@endsection
