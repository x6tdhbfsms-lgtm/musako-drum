@extends('layouts.app')
@section('title', '先生・管理者ダッシュボード | MUSAKO')
@section('content')
<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-amber-700">STAFF DASHBOARD</p><h1 class="text-2xl font-bold sm:text-3xl">おはようございます、{{ auth()->user()->name }}さん</h1></div><a href="{{ route('staff.lesson-slots.create') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-stone-900 px-5 font-semibold text-white">＋ レッスン枠を作成</a></div>
<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><a href="{{ route('staff.reservations.index') }}" class="rounded-2xl bg-amber-400 p-6 shadow-sm"><span class="text-sm font-semibold">予約の承認待ち</span><strong class="mt-2 block text-4xl">{{ $pendingCount }}</strong><span class="mt-3 block text-sm">申請を確認する →</span></a><a href="{{ route('staff.transfer-requests.index') }}" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 shadow-sm"><span class="text-sm text-emerald-800">振替の承認待ち</span><strong class="mt-2 block text-4xl">{{ $pendingTransferCount }}</strong><span class="mt-3 block text-sm">振替を確認する →</span></a><a href="{{ route('staff.membership-status-requests.index') }}" class="rounded-2xl border border-sky-200 bg-sky-50 p-6 shadow-sm"><span class="text-sm text-sky-800">在籍申請の承認待ち</span><strong class="mt-2 block text-4xl">{{ $pendingMembershipCount }}</strong><span class="mt-3 block text-sm">在籍申請を確認する →</span></a><a href="{{ route('staff.lesson-slots.index') }}" class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm"><span class="text-sm text-stone-500">今後のレッスン枠</span><strong class="mt-2 block text-4xl">{{ $upcomingSlotCount }}</strong><span class="mt-3 block text-sm">枠を管理する →</span></a></div>
<section class="mt-8"><div class="flex items-center justify-between"><h2 class="text-xl font-bold">新しい予約申請</h2><a href="{{ route('staff.reservations.index') }}" class="text-sm font-semibold text-amber-700">すべて見る</a></div><div class="mt-4 grid gap-3">@forelse ($pendingReservations as $reservation)<a href="{{ route('staff.reservations.index') }}" class="flex flex-col gap-2 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ $reservation->studentProfile->user->name }}</strong><p class="mt-1 text-sm text-stone-600">{{ $reservation->lessonSlot->starts_at->format('Y年n月j日 H:i') }}</p></div><span class="text-sm font-semibold text-amber-700">確認する →</span></a>@empty<div class="rounded-2xl border border-dashed border-stone-300 bg-white p-8 text-center text-stone-500">承認待ちの申請はありません。</div>@endforelse</div></section>
<section class="mt-8">
    <div class="flex items-center justify-between"><h2 class="text-xl font-bold">本日のお休み・遅刻</h2><span class="rounded-full bg-stone-200 px-3 py-1 text-xs font-semibold">{{ $todayNotices->count() }}件</span></div>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @forelse ($todayNotices as $notice)
            <article class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3"><strong>{{ $notice->reservationRequest->studentProfile->user->name }}</strong><span class="rounded-full {{ $notice->type->value === 'absence' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }} px-3 py-1 text-xs font-semibold">{{ $notice->type->value === 'absence' ? 'お休み' : '遅刻' }}</span></div>
                <p class="mt-2 text-sm font-semibold">{{ $notice->reservationRequest->lessonSlot->starts_at->format('H:i') }}〜{{ $notice->reservationRequest->lessonSlot->ends_at->format('H:i') }}</p>
                @if ($notice->late_minutes || $notice->expected_arrival_at)<p class="mt-1 text-sm text-stone-600">{{ $notice->late_minutes ? $notice->late_minutes.'分遅れ' : '' }}{{ $notice->expected_arrival_at ? ' 到着予定 '.$notice->expected_arrival_at->format('H:i') : '' }}</p>@endif
                @if ($notice->notes)<p class="mt-2 text-sm text-stone-500">{{ $notice->notes }}</p>@endif
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-8 text-center text-stone-500 sm:col-span-2">本日の連絡はありません。</div>
        @endforelse
    </div>
</section>
@endsection
