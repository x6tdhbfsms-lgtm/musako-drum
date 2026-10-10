@extends('layouts.app')
@section('title', '振替申請詳細 | MUSAKO')
@section('content')
<div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-amber-700">TRANSFER DETAIL</p><h1 class="text-2xl font-bold sm:text-3xl">{{ $transferRequest->studentProfile->user->name }}さんの振替申請</h1></div><a href="{{ route('staff.transfer-requests.index') }}" class="text-sm font-semibold text-stone-600">← 一覧へ</a></div>
@if ($errors->any())<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif
<div class="mt-6 grid gap-4 md:grid-cols-2"><section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><span class="text-xs font-semibold text-stone-500">元の予約</span><h2 class="mt-2 text-lg font-bold">{{ $transferRequest->originalReservationRequest->lessonSlot->starts_at->format('Y年n月j日 H:i') }}</h2><p class="mt-2 text-sm text-stone-600">{{ $transferRequest->originalReservationRequest->lessonSlot->teacherProfile->display_name }} · {{ $transferRequest->originalReservationRequest->lessonSlot->venue?->name ?? '会場未定' }}</p></section><section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm"><span class="text-xs font-semibold text-amber-800">希望する振替先</span><h2 class="mt-2 text-lg font-bold">{{ $transferRequest->requestedLessonSlot?->starts_at->format('Y年n月j日 H:i') ?? '日程未定（保留）' }}</h2><p class="mt-2 text-sm text-stone-600">{{ $transferRequest->requestedLessonSlot?->teacherProfile->display_name ?? '日程調整待ち' }} · {{ $transferRequest->requestedLessonSlot?->venue?->name ?? '会場未定' }}</p></section></div>
<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><dl class="grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-stone-500">理由</dt><dd class="mt-1">{{ $transferRequest->reason ?: '未入力' }}</dd></div><div><dt class="text-stone-500">備考</dt><dd class="mt-1">{{ $transferRequest->student_note ?: '未入力' }}</dd></div></dl></section>
<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 text-sm">
    <h2 class="font-bold">年度の振替上限</h2>
    <p class="mt-2">{{ $annualAllowance['year_start']->format('Y年n月') }}〜{{ $annualAllowance['year_end']->format('Y年n月') }}：予定レッスン{{ $annualAllowance['planned_lessons'] }}回の1/4で、上限{{ $annualAllowance['free_transfer_limit'] }}回</p>
    <p class="mt-2 text-stone-600">在籍契約から計算しています。年度途中の入会・契約回数の変更を反映し、端数は切り捨てます。直前の変更・会場キャンセルに伴う料金は別途確認が必要です。</p>
</section>
<section class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm">
    <h2 class="font-bold">{{ $transferRequest->terms_snapshot ? '確定時の追加料金記録' : '今、講師が確定した場合の追加料金（生徒都合）' }}</h2>
    @foreach (['studio_cancellation' => '会場キャンセル料', 'late_transfer' => '直前振替料金', 'excess_lesson' => '年間上限超過・1回分のレッスン料金', 'excess_surcharge' => '年間上限超過・加算'] as $key => $label)
        <p class="mt-2">{{ $label }}：{{ $termsQuote['components'][$key] === null ? '管理者確認が必要' : number_format($termsQuote['components'][$key]).'円' }}</p>
    @endforeach
    <p class="mt-2">正式な申請日時：{{ $termsQuote['formalized_at'] }}／今年度の確定済み振替：{{ $termsQuote['used_before'] }}回</p>
    <p class="mt-2">{{ $transferRequest->terms_snapshot ? '記録済みの追加料金は、保留コマの日程設定では再計算しません。' : '講師の承認時に再計算します。' }}講師都合は追加料金・回数カウントなし。災害等の特例は会場キャンセル料のみです。</p>
    @if ($termsQuote['requires_review'])<p class="mt-2 font-bold text-red-800">会場区分・追加料金または講師都合の月謝精算に確認が必要です。請求確定前に管理者が実施回数と料金を確認してください。</p>@endif
    <p class="mt-2">確定済み請求には自動反映しません。対象月の請求が確定済みの場合は、取消・再発行または別途精算を管理者が確認してください。</p>
</section>
@if ($transferRequest->status->value === 'pending')<form method="post" action="{{ route('staff.transfer-requests.update', $transferRequest) }}" class="mt-5 grid gap-3 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:grid-cols-[1fr_auto_auto]">@csrf @method('patch')<label class="grid gap-2 text-sm sm:col-span-3">理由区分<select name="cause" class="min-h-11 rounded-xl border border-stone-300 px-3"><option value="student">生徒都合</option><option value="teacher">講師都合（回数カウントなし）</option><option value="emergency">災害・公共交通機関の事故等（理由必須）</option></select></label><input name="staff_note" maxlength="1000" placeholder="生徒へのメモ（任意）" class="min-h-12 rounded-xl border border-stone-300 px-3"><button name="decision" value="rejected" class="min-h-12 rounded-xl border border-red-200 px-5 font-semibold text-red-700">却下</button><button name="decision" value="approved" class="min-h-12 rounded-xl bg-emerald-600 px-6 font-semibold text-white">{{ $transferRequest->requested_lesson_slot_id === null ? '保留を承認する' : '正式に確定する' }}</button></form>@else<div class="mt-5 rounded-2xl bg-stone-100 p-5 text-sm"><strong>{{ $transferRequest->statusLabel() }}</strong><p class="mt-1 text-stone-600">{{ $transferRequest->reviewer?->name }} · {{ $transferRequest->reviewed_at?->format('Y/m/d H:i') }}</p>@if ($transferRequest->staff_note)<p class="mt-2">{{ $transferRequest->staff_note }}</p>@endif</div>@endif
@if ($transferRequest->isAwaitingSchedule())
<section class="mt-6 rounded-2xl border border-violet-200 bg-violet-50 p-5">
    <h2 class="text-lg font-bold">保留コマの日程を設定</h2>
    <p class="mt-2 text-sm">元のレッスンと同じ講師・会場・コース・時間数で登録します。翌月以降も選べます。期限・追加料金は保留承認時の記録を維持します。</p>
    <form method="post" action="{{ route('staff.transfer-requests.schedule', $transferRequest) }}" class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto]">
        @csrf
        <label class="grid gap-2 text-sm font-bold">振替先の日付・開始時刻<input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" min="{{ today()->format('Y-m-d\TH:i') }}" step="900" required class="min-h-12 w-full min-w-0 rounded-xl border border-stone-300 bg-white p-3"></label>
        <button class="min-h-12 self-end rounded-xl bg-violet-700 px-5 font-bold text-white">この日時でカレンダーに追加</button>
    </form>
    <p class="mt-3 text-xs text-stone-600">平日12:00〜21:00・土日10:00〜20:00の範囲で、studio34は00分・30分開始。予約の重複と会場移動15分を確認してから登録します。</p>
    <a href="{{ route('staff.dashboard', ['view' => 'week']) }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-bold underline">先生のカレンダーを別画面で確認</a>
</section>
@endif
@endsection
