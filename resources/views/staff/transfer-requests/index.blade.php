@extends('layouts.app')
@section('title', '振替申請管理 | MUSAKO')
@section('content')
<nav class="mb-5 flex flex-wrap gap-3 text-sm font-bold"><a href="{{ route('staff.transfer-requests.index') }}" class="rounded-xl border border-stone-300 px-4 py-3">すべての振替</a><a href="{{ route('staff.transfer-requests.deferred') }}" class="rounded-xl bg-violet-100 px-4 py-3 text-violet-900">保留レッスン一覧</a></nav>
@if (request()->routeIs('staff.transfer-requests.deferred'))
<section class="mb-5 rounded-2xl bg-violet-50 p-5"><h1 class="text-2xl font-bold">保留レッスン一覧</h1><p class="mt-2 text-sm">保留申請を承認後、予定が決まり次第「詳細」から日付と時刻を設定してください。元のレッスン月を引き継ぎ、翌月以降のカレンダーにも追加できます。</p></section>
@endif
@unless (request()->routeIs('staff.transfer-requests.deferred'))
<div><p class="text-sm font-semibold text-amber-700">TRANSFER REQUESTS</p><h1 class="text-2xl font-bold sm:text-3xl">振替申請管理</h1><p class="mt-2 text-sm text-stone-600">承認待ちを確認し、詳細画面から処理します。</p></div>
@endunless
<div class="mt-7 grid gap-4">@forelse ($transferRequests as $transferRequest)<a href="{{ route('staff.transfer-requests.show', $transferRequest) }}" class="rounded-2xl border {{ $transferRequest->status->value === 'pending' ? 'border-amber-300' : 'border-stone-200' }} bg-white p-4 shadow-sm hover:border-amber-400 sm:p-5"><div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><div class="flex flex-wrap items-center gap-2"><strong>{{ $transferRequest->studentProfile->user->name }}</strong><span class="rounded-full px-3 py-1 text-xs font-semibold {{ ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-red-100 text-red-700'][$transferRequest->status->value] }}">{{ $transferRequest->statusLabel() }}</span></div><p class="mt-2 text-sm font-semibold">{{ $transferRequest->originalReservationRequest->lessonSlot->starts_at->format('n/j H:i') }} → {{ $transferRequest->requestedLessonSlot?->starts_at->format('n/j H:i') ?? '日程未定（保留）' }}</p></div><span class="text-sm font-semibold text-amber-700">詳細を確認 →</span></div></a>@empty<div class="rounded-2xl border border-dashed border-stone-300 bg-white p-10 text-center text-stone-500">振替申請はありません。</div>@endforelse</div><div class="mt-6">{{ $transferRequests->links() }}</div>
@endsection
