@extends('layouts.app')
@section('title', 'レッスン枠管理 | MUSAKO')
@section('content')
<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div><p class="text-sm font-semibold text-amber-700">SCHEDULE</p><h1 class="text-2xl font-bold sm:text-3xl">レッスン枠管理</h1><p class="mt-2 text-sm text-stone-600">生徒が申し込める日時を登録・管理します。</p></div>
    <a href="{{ route('staff.lesson-slots.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-stone-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-stone-700">＋ 新しい枠を作成</a>
</div>

<div class="mt-7 grid gap-4">
    @forelse ($lessonSlots as $lessonSlot)
        <article class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex gap-4">
                    <div class="min-w-16 rounded-xl bg-amber-50 px-3 py-2 text-center"><span class="block text-xs font-semibold text-amber-700">{{ $lessonSlot->starts_at->format('n月') }}</span><strong class="text-2xl">{{ $lessonSlot->starts_at->format('j') }}</strong><span class="block text-xs text-stone-500">{{ ['日','月','火','水','木','金','土'][$lessonSlot->starts_at->dayOfWeek] }}</span></div>
                    <div><h2 class="font-bold">{{ $lessonSlot->starts_at->format('H:i') }}〜{{ $lessonSlot->ends_at->format('H:i') }}</h2><p class="mt-1 text-sm text-stone-600">{{ $lessonSlot->teacherProfile->display_name }} · {{ $lessonSlot->course?->name ?? 'コース指定なし' }}</p><p class="mt-1 text-xs text-stone-500">{{ $lessonSlot->venue?->name ?? '会場未定' }} · 定員{{ $lessonSlot->capacity }}名 · 申請{{ $lessonSlot->reservation_requests_count }}件</p></div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $lessonSlot->status->value === 'open' ? 'bg-emerald-100 text-emerald-800' : ($lessonSlot->status->value === 'closed' ? 'bg-stone-200 text-stone-700' : 'bg-red-100 text-red-700') }}">{{ ['open' => '受付中', 'closed' => '締切', 'cancelled' => '中止'][$lessonSlot->status->value] }}</span>
                    <a href="{{ route('staff.lesson-slots.edit', $lessonSlot) }}" class="min-h-11 rounded-xl border border-stone-300 px-4 py-3 text-sm font-semibold hover:bg-stone-50">編集</a>
                    <form method="post" action="{{ route('staff.lesson-slots.destroy', $lessonSlot) }}" onsubmit="return confirm('この枠を削除しますか？')">@csrf @method('delete')<button class="min-h-11 rounded-xl border border-red-200 px-4 py-3 text-sm font-semibold text-red-700 hover:bg-red-50">削除</button></form>
                </div>
            </div>
        </article>
    @empty
        <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center text-stone-500">まだレッスン枠がありません。</div>
    @endforelse
</div>
<div class="mt-6">{{ $lessonSlots->links() }}</div>
@endsection
