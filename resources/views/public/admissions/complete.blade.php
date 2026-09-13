@extends('layouts.app')
@section('title', '入会申込み受付完了 | MUSAKO')
@section('content')
<div class="mx-auto max-w-2xl rounded-3xl border border-emerald-200 bg-white p-6 text-center shadow-sm sm:p-10"><span class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-100 text-2xl">✓</span><h1 class="mt-5 text-2xl font-bold">入会申込みを受け付けました</h1><p class="mt-3 text-sm leading-7 text-stone-600">承認前に生徒アカウントは作成されません。教室で確認後、メールでご連絡します。</p><div class="mx-auto mt-6 max-w-sm rounded-2xl bg-stone-100 p-4"><p class="text-xs text-stone-500">受付番号</p><strong class="mt-1 block tracking-wider">{{ $application->public_reference }}</strong></div></div>
@endsection
