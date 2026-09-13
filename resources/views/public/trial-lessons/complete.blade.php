@extends('layouts.app')
@section('title', '体験レッスン受付完了 | MUSAKO')
@section('content')
<div class="mx-auto max-w-2xl rounded-3xl border border-emerald-200 bg-white p-6 text-center shadow-sm sm:p-10"><span class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</span><h1 class="mt-5 text-2xl font-bold">体験レッスン申込みを受け付けました</h1><p class="mt-3 text-sm leading-7 text-stone-600">教室で内容を確認後、メールでご連絡します。</p><div class="mx-auto mt-6 max-w-sm rounded-2xl bg-stone-100 p-4"><p class="text-xs text-stone-500">受付番号</p><strong class="mt-1 block tracking-wider">{{ $trialLessonRequest->public_reference }}</strong></div>@if (session('trial_access_token'))<a href="{{ route('trial-lessons.manage', [$trialLessonRequest->public_reference, session('trial_access_token')]) }}" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-xl border border-stone-300 px-5 font-bold">申込み内容・キャンセルを確認</a>@endif</div>
@endsection
