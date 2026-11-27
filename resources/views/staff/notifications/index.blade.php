@extends('layouts.app')
@section('title', '通知一覧 | MUSAKO')
@section('content')
<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <p class="text-sm font-semibold tracking-widest text-amber-700">ADMIN NOTIFICATIONS</p>
        <h1 class="mt-1 text-2xl font-bold sm:text-3xl">通知一覧</h1>
        <p class="mt-2 text-sm text-stone-600">生徒からの申請と、休会期限が近い生徒を確認できます。</p>
    </div>
    <a href="{{ route('staff.dashboard') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-stone-300 bg-white px-4 text-sm font-semibold">Homeへ戻る</a>
</div>

<section class="mt-6 grid gap-3 sm:grid-cols-2" aria-label="通知の件数">
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
        <span class="text-sm font-semibold text-amber-900">確認が必要な申請・問い合わせ</span>
        <strong class="mt-1 block text-3xl text-stone-900">{{ $pendingRequestCount }}<span class="ml-1 text-base">件</span></strong>
    </div>
    <div class="rounded-2xl border {{ $upcomingPauseDeadlineCount > 0 ? 'border-red-300 bg-red-50' : 'border-stone-200 bg-white' }} p-4">
        <span class="text-sm font-semibold {{ $upcomingPauseDeadlineCount > 0 ? 'text-red-800' : 'text-stone-600' }}">30日以内・期限超過の休会</span>
        <strong class="mt-1 block text-3xl text-stone-900">{{ $upcomingPauseDeadlineCount }}<span class="ml-1 text-base">件</span></strong>
    </div>
</section>

<section class="mt-7" aria-labelledby="notification-list-title">
    <div class="flex items-center justify-between gap-3">
        <h2 id="notification-list-title" class="text-xl font-bold">確認が必要な項目</h2>
        <span class="text-sm text-stone-500">{{ $notifications->count() }}件</span>
    </div>
    <div class="mt-4 grid gap-3">
        @forelse ($notifications as $notification)
            <a href="{{ $notification['url'] }}" class="block rounded-2xl border {{ $notification['urgent'] ? 'border-red-300 bg-red-50' : 'border-stone-200 bg-white' }} p-4 shadow-sm transition hover:border-amber-400 sm:p-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <strong class="text-base">{{ $notification['title'] }}</strong>
                            @if ($notification['urgent'])<span class="rounded-full bg-red-600 px-2.5 py-1 text-xs font-bold text-white">要確認</span>@endif
                        </div>
                        <p class="mt-1 font-semibold text-stone-800">{{ $notification['student'] }}さん</p>
                        <p class="mt-1 text-sm text-stone-600">{{ $notification['message'] }}</p>
                    </div>
                    <time class="shrink-0 text-sm text-stone-500" datetime="{{ $notification['date']->toIso8601String() }}">{{ $notification['date']->format('Y年n月j日') }}</time>
                </div>
            </a>
        @empty
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-8 text-center text-stone-500">現在、確認が必要な通知はありません。</div>
        @endforelse
    </div>
</section>
@endsection
