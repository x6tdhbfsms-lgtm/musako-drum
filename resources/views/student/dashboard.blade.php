@extends('layouts.app')
@section('title', 'マイページ | MUSAKO')
@section('content')
<div class="overflow-hidden rounded-3xl bg-stone-900 p-6 text-white shadow-lg sm:p-9">
    <p class="text-sm font-semibold tracking-widest text-amber-300">MY PAGE</p><h1 class="mt-2 text-2xl font-bold sm:text-4xl">こんにちは、{{ auth()->user()->name }}さん</h1><p class="mt-3 max-w-xl text-sm leading-6 text-stone-300">レッスンの予約申請や、確定した予定の確認ができます。</p>
    <a href="{{ route('student.lesson-slots.index') }}" class="mt-6 inline-flex min-h-12 items-center justify-center rounded-xl bg-amber-400 px-6 font-bold text-stone-900 hover:bg-amber-300">空き枠を探す</a>
</div>
<div class="mt-6 grid gap-4 sm:grid-cols-3">
    <a href="{{ route('student.reservations.index') }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><span class="text-sm text-stone-500">承認待ち</span><strong class="mt-2 block text-3xl">{{ $pendingCount }}</strong></a>
    <a href="{{ route('student.reservations.index') }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><span class="text-sm text-stone-500">確定予約</span><strong class="mt-2 block text-3xl">{{ $approvedCount }}</strong></a>
    <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:col-span-1"><span class="text-sm text-stone-500">次回レッスン</span>@if ($nextReservation)<strong class="mt-2 block text-lg">{{ $nextReservation->lessonSlot->starts_at->format('n/j H:i') }}</strong><p class="mt-1 text-sm text-stone-600">{{ $nextReservation->lessonSlot->teacherProfile->display_name }} · {{ $nextReservation->lessonSlot->venue?->name ?? '会場未定' }}</p>@else<p class="mt-3 text-sm text-stone-500">確定した予定はありません</p>@endif</div>
</div>
@endsection
