@extends('layouts.app')
@section('title', '振替の最終確認 | MUSAKO')
@section('content')
<div class="mx-auto max-w-3xl">
    <p class="text-sm font-semibold tracking-widest text-amber-700">TRANSFER REVIEW</p>
    <h1 class="mt-1 text-2xl font-bold sm:text-3xl">振替の最終確認</h1>
    <p class="mt-2 text-sm text-stone-600">内容を確認して申請してください。申請後、先生の確認をもって正式に確定します。</p>

    @if ($confirmationErrors->isNotEmpty())
        <div role="alert" class="mt-5 rounded-2xl border-2 border-red-300 bg-red-50 p-5 text-red-900">
            <h2 class="font-bold">この内容では振替を申請できません</h2>
            <ul class="mt-2 list-inside list-disc text-sm">@foreach ($confirmationErrors as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @if ($errors->any())
        <div role="alert" class="mt-5 rounded-2xl border-2 border-red-300 bg-red-50 p-5 text-red-900"><strong>申請できませんでした</strong><p class="mt-1 text-sm">{{ $errors->first() }}</p></div>
    @endif

    <section class="mt-6 grid gap-4 sm:grid-cols-2">
        <article class="rounded-2xl bg-stone-900 p-5 text-white">
            <p class="text-sm text-stone-300">振替元</p>
            <h2 class="mt-2 font-bold">{{ $reservationRequest->lessonSlot->starts_at->format('Y年n月j日 H:i') }}〜{{ $reservationRequest->lessonSlot->ends_at->format('H:i') }}</h2>
            <p class="mt-2 text-sm text-stone-300">{{ $reservationRequest->lessonSlot->venue?->name ?? '会場未定' }}</p>
        </article>
        <article class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-5">
            <p class="text-sm font-semibold text-amber-900">希望する振替先</p>
            @if (($data['defer_schedule'] ?? false))
                <h2 class="mt-2 font-bold">日程未定（保留を希望）</h2>
                <p class="mt-2 text-sm text-stone-600">先生の承認後、日程が決まった時点で枠を設定します。</p>
            @else
                <h2 class="mt-2 font-bold">{{ $target?->starts_at?->format('Y年n月j日 H:i') }}〜{{ $target?->ends_at?->format('H:i') }}</h2>
                <p class="mt-2 text-sm text-stone-600">{{ $target?->venue?->name ?? '会場未定' }}</p>
            @endif
        </article>
    </section>

    <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
        <h2 class="font-bold">規約と追加料金の目安</h2>
        <p class="mt-2 text-sm text-stone-600">通常申請の期限は、振替元レッスン前日の12:00です。期限を過ぎた申請は受け付けできません。休会・退会月以降への振替、すでに振替済みのレッスンの再振替もできません。</p>
        <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
            <div class="flex justify-between gap-3 rounded-lg bg-stone-50 p-3"><dt>会場キャンセル料</dt><dd class="font-bold">{{ is_null($quote['components']['studio_cancellation']) ? '確認が必要' : number_format($quote['components']['studio_cancellation']).'円' }}</dd></div>
            <div class="flex justify-between gap-3 rounded-lg bg-stone-50 p-3"><dt>直前の振替料金</dt><dd class="font-bold">{{ is_null($quote['components']['late_transfer']) ? '確認が必要' : number_format($quote['components']['late_transfer']).'円' }}</dd></div>
            @if (($quote['components']['excess_surcharge'] ?? 0) > 0)
                <div class="flex justify-between gap-3 rounded-lg bg-stone-50 p-3"><dt>年間上限超過分</dt><dd class="font-bold">{{ number_format($quote['components']['excess_lesson'] ?? 0) }}円＋2,000円</dd></div>
            @endif
            <div class="flex justify-between gap-3 rounded-lg bg-amber-100 p-3"><dt>現在の規約での目安</dt><dd class="font-bold">{{ $quote['requires_review'] || is_null($quote['total']) ? '先生の確認が必要' : number_format($quote['total']).'円' }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-stone-500">追加料金は先生が正式に確定した日時と理由で最終判定します。表示額は現在時点の目安です。電子ドラムの会場キャンセル料はかかりません。</p>
    </section>

    @if (!empty($data['reason']))<section class="mt-4 rounded-2xl bg-white p-5 shadow-sm"><h2 class="font-bold">申請理由</h2><p class="mt-2 whitespace-pre-wrap text-sm">{{ $data['reason'] }}</p></section>@endif
    @if (!empty($data['student_note']))<section class="mt-4 rounded-2xl bg-white p-5 shadow-sm"><h2 class="font-bold">備考</h2><p class="mt-2 whitespace-pre-wrap text-sm">{{ $data['student_note'] }}</p></section>@endif

    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
        <a href="{{ route('student.transfer-requests.create', $reservationRequest) }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-stone-300 px-5 font-semibold">日時を選び直す</a>
        @if ($canConfirm)
            <form method="post" action="{{ route('student.transfer-requests.store', $reservationRequest) }}">
                @csrf
                @if (!empty($data['defer_schedule']))<input type="hidden" name="defer_schedule" value="1">@else
                    @if (!empty($data['requested_starts_at']))<input type="hidden" name="requested_starts_at" value="{{ $data['requested_starts_at'] }}">@else<input type="hidden" name="requested_lesson_slot_id" value="{{ $data['requested_lesson_slot_id'] }}">@endif
                @endif
                @if (!empty($data['special_handling']))<input type="hidden" name="special_handling" value="1">@endif
                @if (!empty($data['reason']))<input type="hidden" name="reason" value="{{ $data['reason'] }}">@endif
                @if (!empty($data['student_note']))<input type="hidden" name="student_note" value="{{ $data['student_note'] }}">@endif
                <button class="min-h-12 rounded-xl bg-amber-400 px-6 font-bold text-stone-900">この内容で振替を申請する</button>
            </form>
        @else
            <button disabled class="min-h-12 cursor-not-allowed rounded-xl bg-stone-200 px-6 font-bold text-stone-500">申請できません</button>
        @endif
    </div>
</div>
@endsection
