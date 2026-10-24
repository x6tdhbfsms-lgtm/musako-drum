@extends('layouts.app')
@section('title', '振替申請 | MUSAKO')
@section('content')
<div><p class="text-sm font-semibold text-amber-700">TRANSFER</p><h1 class="text-2xl font-bold sm:text-3xl">振替するレッスンを選ぶ</h1><p class="mt-2 text-sm text-stone-600">ご自身の予約済みレッスンをすべて確認できます。振替したいコマの「このレッスンを振替」を押してください。振替日が未定の場合は、次の画面から保留申請できます。</p></div>
<aside class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p>レッスン予定日より4日を切ってのご申請は、振替料金1,000円が発生します（電子ドラムコースの前日申請は、現在の規約では追加なし）。</p><p class="mt-2 font-semibold">前日のお昼12:00以降は通常の振替申請ができません。</p><p class="mt-2">料金・期限は講師が正式に確定した日時で判定します。自然災害などの特例は先生へご相談ください。</p></aside>
<section class="mt-6 space-y-3" aria-label="自分のレッスン一覧">
@forelse ($lessons as $lesson)
    @php
        $unavailable = match (true) {
            $lesson->status->value !== 'approved' => ['pending' => '予約の承認待ちです。', 'cancelled' => 'キャンセル済みのレッスンです。', 'rejected' => '却下された予約です。'][$lesson->status->value],
            !$lesson->lessonSlot->starts_at->isFuture() => '開始時刻を過ぎています。必要な場合は先生へご相談ください。',
            $lesson->resultingTransferRequest?->status->value === 'approved' => '振替済みのレッスンは再度振替できません。',
            (bool) $lesson->has_pending_transfer => '振替申請中です。先生の確認をお待ちください。',
            default => null,
        };
        $late = now()->greaterThanOrEqualTo($lesson->transferRequestDeadline());
    @endphp
    <article class="min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0"><h2 class="text-lg font-bold">{{ $lesson->lessonSlot->starts_at->format('Y年n月j日 H:i') }}〜{{ $lesson->lessonSlot->ends_at->format('H:i') }}</h2><p class="mt-2 break-words text-sm">{{ $lesson->lessonSlot->course?->name ?? 'コース未設定' }} · {{ $lesson->lessonSlot->venue?->name ?? '会場未定' }}</p><p class="mt-1 text-sm text-stone-600">担当：{{ $lesson->lessonSlot->teacherProfile?->display_name ?? '講師未定' }}</p>
            @if ($unavailable)<p class="mt-2 text-sm text-stone-500">{{ $unavailable }}</p>@else<p class="mt-2 text-xs text-stone-500">通常の申請期限：{{ $lesson->transferRequestDeadline()->format('Y/m/d H:i') }}</p>@if ($late)<p class="mt-1 text-sm text-amber-800">通常の期限を過ぎています。自然災害などの特例は先生の確認が必要です。</p>@endif @endif</div>
            <div class="grid shrink-0 gap-2">
                @if (!$unavailable)<a href="{{ route('student.transfer-requests.create', $lesson) }}" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-amber-400 px-5 text-sm font-bold">{{ $late ? '特例の振替を相談' : 'このレッスンを振替' }}</a>@endif
                <a href="{{ route('student.reservations.show', $lesson) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-stone-300 px-4 text-sm font-semibold">予約詳細</a>
            </div>
        </div>
    </article>
@empty<p class="rounded-2xl border border-dashed border-stone-300 bg-white p-6 text-sm text-stone-500">予約済みのレッスンはありません。</p>@endforelse
</section>
<div class="mt-4">{{ $lessons->links() }}</div>
<h2 class="mt-9 text-xl font-bold">振替申請履歴</h2>
<div class="mt-7 grid gap-4">@forelse ($transferRequests as $transferRequest)<article class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5"><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong>{{ $transferRequest->originalReservationRequest->lessonSlot->starts_at->format('n/j H:i') }} → {{ $transferRequest->requestedLessonSlot?->starts_at->format('n/j H:i') ?? '日程未定（保留）' }}</strong><span class="rounded-full px-3 py-1 text-xs font-semibold {{ ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-red-100 text-red-700'][$transferRequest->status->value] }}">{{ $transferRequest->statusLabel() }}</span></div><p class="mt-2 text-sm text-stone-600">振替先: {{ $transferRequest->requestedLessonSlot?->teacherProfile->display_name ?? '日程調整待ち' }} · {{ $transferRequest->requestedLessonSlot?->venue?->name ?? '会場未定' }}</p>@if ($transferRequest->staff_note)<p class="mt-2 text-sm text-stone-500">先生から: {{ $transferRequest->staff_note }}</p>@endif</div><time class="text-xs text-stone-500">申請 {{ $transferRequest->requested_at->format('Y/m/d H:i') }}</time></div></article>@empty<div class="rounded-2xl border border-dashed border-stone-300 bg-white p-10 text-center text-stone-500">振替申請はまだありません。</div>@endforelse</div>
<div class="mt-6">{{ $transferRequests->links() }}</div>
@endsection
