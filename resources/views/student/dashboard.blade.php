@extends('layouts.app')
@section('title', 'レッスンカレンダー | MUSAKO')
@section('content')
<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div><p class="text-sm font-semibold tracking-widest text-amber-700">MY CALENDAR</p><h1 class="mt-1 text-2xl font-bold sm:text-4xl">こんにちは、{{ auth()->user()->name }}さん</h1><p class="mt-1 text-xs text-stone-500">ログイン名：<span class="font-semibold">{{ auth()->user()->login_name }}</span></p><p class="mt-2 text-sm text-stone-600">ご自身のレッスンと申請中の予定を確認できます。</p></div>
</div>
@if ($errors->any())<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif

<section class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5" aria-labelledby="next-lesson-title">
    <h2 id="next-lesson-title" class="text-sm font-bold text-emerald-800">次回のレッスン</h2>
    @if ($nextReservation)
        <a href="{{ route('student.reservations.show', $nextReservation) }}" class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div><strong class="block text-2xl tabular-nums">{{ $nextReservation->lessonSlot->starts_at->format('n月j日') }}（{{ ['日','月','火','水','木','金','土'][$nextReservation->lessonSlot->starts_at->dayOfWeek] }}） {{ $nextReservation->lessonSlot->starts_at->format('H:i') }}〜{{ $nextReservation->lessonSlot->ends_at->format('H:i') }}</strong><p class="mt-2 text-sm">{{ $nextReservation->lessonSlot->venue?->name ?? '会場未定' }}</p></div>
            <span class="text-sm font-bold text-emerald-800">詳細を見る →</span>
        </a>
        <div class="mt-4 rounded-xl border border-emerald-200 bg-white p-4">
            <h3 class="text-sm font-bold text-emerald-900">次回レッスンカルテ</h3>
            @if ($nextReservation->lesson_content)
                <p class="mt-2 whitespace-pre-line text-sm text-stone-700">{{ $nextReservation->lesson_content }}</p>
            @else
                <p class="mt-2 text-sm text-stone-500">レッスン内容はまだ登録されていません。</p>
            @endif
        </div>
    @else
        <p class="mt-2 text-sm text-stone-600">確定した今後のレッスンはありません。</p>
    @endif
</section>

<section class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm" aria-labelledby="my-lesson-title">
    <h2 id="my-lesson-title" class="text-xl font-bold">あなたのレッスン内容</h2>
    <p class="mt-1 text-xs text-stone-600">現在の契約と、登録済みの今後の契約内容です。</p>
    <div class="mt-4 grid gap-4">
        @forelse ($displayEnrollments as $enrollment)
            <article class="min-w-0 rounded-xl bg-white p-4">
                @php
                    $weeks = $enrollment->regular_week_numbers ?: ($enrollment->monthly_lesson_limit == 2 ? [1, 3] : ($enrollment->monthly_lesson_limit > 0 ? range(1, min(4, $enrollment->monthly_lesson_limit)) : []));
                    $fixedSchedule = $enrollment->lesson_type === App\Enums\LessonType::Regular && $enrollment->weekday !== null && $enrollment->starts_at_time;
                @endphp
                <p class="text-xs text-stone-600">{{ $enrollment->starts_on->isAfter(today()) ? '開始予定：' : '契約期間：' }}{{ $enrollment->starts_on->format('Y年n月j日') }}〜{{ $enrollment->ends_on?->format('Y年n月j日') }}</p>
                <h3 class="break-words text-lg font-bold">{{ $enrollment->course?->name ?? 'コース確認中' }}</h3>
                <p class="mt-2 text-lg font-bold text-amber-800">1回 {{ $enrollment->lesson_minutes ?? '未設定' }}分 · 月{{ $enrollment->monthly_lesson_limit ?? '未設定' }}回@if ($fixedSchedule) · 第{{ implode('・第', $weeks) }}{{ ['日','月','火','水','木','金','土'][$enrollment->weekday] }}曜日 {{ substr($enrollment->starts_at_time, 0, 5) }}〜@endif</p>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div class="min-w-0"><dt class="text-xs text-stone-500">会場</dt><dd class="mt-1 break-words font-semibold">{{ $enrollment->venue?->name ?? '会場未定' }}</dd></div>
                    <div><dt class="text-xs text-stone-500">レッスン形式</dt><dd class="mt-1 font-semibold">{{ $enrollment->lesson_type?->label() ?? '確認中' }}</dd></div>
                    @if (! $fixedSchedule)<div><dt class="text-xs text-stone-500">曜日・時間</dt><dd class="mt-1 font-semibold">{{ $enrollment->lesson_type === App\Enums\LessonType::Flex ? '予約ごとに調整' : '固定日時未登録（各レッスンの予定をご確認ください）' }}</dd></div>@endif
                </dl>
            </article>
        @empty
            <p class="text-sm text-stone-600">現在有効な契約はありません。レッスン内容は教室へお問い合わせください。</p>
        @endforelse
    </div>
    @if ($annualTransferAllowance)
        <div class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4" aria-label="年間の振替可能回数">
            <h3 class="font-bold text-indigo-950">年間の振替可能回数</h3>
            <p class="mt-1 text-sm text-indigo-900">{{ $annualTransferAllowance['year_start']->format('Y年n月') }}〜{{ $annualTransferAllowance['year_end']->format('Y年n月') }}：無料振替 {{ $annualTransferAllowance['free_transfer_limit'] }}回</p>
            <p class="mt-1 text-sm font-semibold text-indigo-950">使用済み {{ $annualTransferAllowance['used_transfers'] }}回 · 残り {{ $annualTransferAllowance['remaining_transfers'] }}回</p>
            <p class="mt-1 text-xs text-indigo-800">講師都合の振替は使用回数に含みません。上限を超える場合は規約に基づく追加料金がかかります。</p>
        </div>
    @endif
</section>

@if ($monthlySummary)
<section class="mt-6 rounded-2xl border border-amber-200 bg-gradient-to-br from-amber-50 to-white p-5 shadow-sm" aria-labelledby="monthly-summary-title">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div><p class="text-xs font-semibold tracking-widest text-amber-700">MONTHLY LESSONS</p><h2 id="monthly-summary-title" class="mt-1 text-xl font-bold">{{ $month->format('n月') }}のレッスン</h2><p class="mt-1 text-sm text-stone-600">実施済みと、予約・振替の申請中や日程保留中の回数です。</p></div>
    </div>
    <dl id="monthly-status-summary" class="mt-5 grid grid-cols-2 gap-3 text-sm">
        @foreach ([['実施済み', $monthlySummary->completed], ['申請中', $monthlySummary->pending]] as [$label, $count])
            <div class="rounded-xl border border-stone-200 bg-white p-4"><dt class="text-sm text-stone-500">{{ $label }}</dt><dd class="mt-1 text-2xl font-bold">{{ $count }}<span class="ml-0.5 text-sm font-normal">回</span></dd></div>
        @endforeach
    </dl>
</section>
@endif


<section class="mt-6" aria-labelledby="lesson-calendar-title">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><h2 id="lesson-calendar-title" class="text-xl font-bold">レッスンカレンダー</h2><a href="{{ route('student.reservations.index') }}" class="text-sm font-semibold text-amber-700">予約一覧を見る →</a></div>
    <div class="mt-4">
    <div class="flex items-center justify-between gap-2 rounded-xl border border-stone-200 bg-white p-1 shadow-sm"><a href="{{ route('student.dashboard', ['month' => $month->subMonth()->format('Y-m')]) }}" class="grid min-h-11 min-w-11 place-items-center rounded-lg hover:bg-stone-100" aria-label="前月">←</a><strong class="min-w-28 text-center">{{ $month->format('Y年 n月') }}</strong><a href="{{ route('student.dashboard', ['month' => $month->addMonth()->format('Y-m')]) }}" class="grid min-h-11 min-w-11 place-items-center rounded-lg hover:bg-stone-100" aria-label="翌月">→</a></div>
    </div>
    <div class="mt-3 flex flex-wrap gap-2 text-xs">
        @foreach ([['振替申請中','bg-violet-100 text-violet-800'], ['振替確定','bg-indigo-100 text-indigo-800'], ['お休み連絡済み','bg-red-100 text-red-700'], ['遅刻連絡済み','bg-orange-100 text-orange-800'], ['休講／予約不可','bg-stone-100 text-stone-500']] as [$label, $classes])<span class="rounded-full px-3 py-1 font-semibold {{ $classes }}">{{ $label }}</span>@endforeach
    </div>

    <div class="mt-4 hidden overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm md:block">
        <div class="grid grid-cols-7 border-b border-stone-200 bg-stone-50 text-center text-xs font-semibold text-stone-500">@foreach (['日','月','火','水','木','金','土'] as $dayName)<div class="py-3">{{ $dayName }}</div>@endforeach</div>
        <div class="grid grid-cols-7">
            @foreach ($calendarDays as $day)
                @php
                    $entries = $calendarEntriesByDay->get($day->format('Y-m-d'), collect());
                @endphp
                <section class="min-h-36 min-w-0 border-b border-r border-stone-100 p-2 {{ $day->month !== $month->month ? 'bg-stone-50 text-stone-400' : '' }}"><div class="flex items-center justify-between"><span class="text-xs font-bold {{ $day->isToday() ? 'grid size-7 place-items-center rounded-full bg-stone-900 text-white' : '' }}">{{ $day->day }}</span><a href="{{ route('student.dashboard', ['month' => $month->format('Y-m'), 'date' => $day->format('Y-m-d')]) }}" class="text-[10px] text-stone-400">一覧</a></div><div class="mt-2 grid gap-1.5">@foreach ($entries as $entry) @include('student.calendar._entry', ['entry' => $entry, 'compact' => true]) @endforeach</div></section>
            @endforeach
        </div>
    </div>

    <div class="mt-4 md:hidden">
        <div class="rounded-2xl border border-stone-200 bg-white p-3 shadow-sm">
            <div class="grid grid-cols-7 text-center text-[11px] font-semibold text-stone-400">
                @foreach (['日', '月', '火', '水', '木', '金', '土'] as $dayName)<span class="py-1">{{ $dayName }}</span>@endforeach
            </div>
            <div class="mt-1 grid grid-cols-7 gap-1">
                @foreach ($calendarDays as $day)
                    @php
                        $dayEntries = $calendarEntriesByDay->get($day->format('Y-m-d'), collect());
                        $reservationEntries = $dayEntries->filter(fn ($entry) => $entry['reservation'] !== null)->values();
                    @endphp
                    @if ($reservationEntries->count() === 1)
                        @php $reservationEntry = $reservationEntries->first(); @endphp
                        <a data-mobile-reservation-day-detail-link href="{{ route('student.reservations.show', $reservationEntry['reservation']) }}" aria-label="{{ $reservationEntry['slot']->starts_at->format('n月j日 H:i') }}の予約詳細を見る" class="relative grid min-h-11 min-w-0 place-items-center rounded-xl text-sm font-semibold touch-manipulation {{ $selectedDate->isSameDay($day) ? 'bg-stone-900 text-white' : ($day->month === $month->month ? 'hover:bg-stone-100' : 'text-stone-300') }}">{{ $day->day }}<span class="absolute bottom-1 size-1 rounded-full {{ $selectedDate->isSameDay($day) ? 'bg-amber-300' : 'bg-amber-500' }}"></span></a>
                        @elseif ($dayEntries->isNotEmpty())
                        <a href="{{ route('student.dashboard', ['month' => $month->format('Y-m'), 'date' => $day->format('Y-m-d')]) }}" class="relative grid min-h-11 min-w-0 place-items-center rounded-xl text-sm font-semibold {{ $selectedDate->isSameDay($day) ? 'bg-stone-900 text-white' : ($day->month === $month->month ? 'hover:bg-stone-100' : 'text-stone-300') }}">{{ $day->day }}<span class="absolute bottom-1 size-1 rounded-full {{ $selectedDate->isSameDay($day) ? 'bg-amber-300' : 'bg-amber-500' }}"></span></a>
                    @else
                        <a href="{{ route('student.dashboard', ['month' => $month->format('Y-m'), 'date' => $day->format('Y-m-d')]) }}" class="relative grid min-h-11 min-w-0 place-items-center rounded-xl text-sm font-semibold {{ $selectedDate->isSameDay($day) ? 'bg-stone-900 text-white' : ($day->month === $month->month ? 'hover:bg-stone-100' : 'text-stone-300') }}">{{ $day->day }}</a>
                    @endif
                @endforeach
            </div>
        </div>
        <div class="mt-4"><h3 class="text-lg font-bold">{{ $selectedDate->format('n月j日') }}（{{ ['日','月','火','水','木','金','土'][$selectedDate->dayOfWeek] }}）</h3><div class="mt-3 grid gap-3">@forelse ($calendarEntriesByDay->get($selectedDate->format('Y-m-d'), collect()) as $entry) @include('student.calendar._entry', ['entry' => $entry, 'compact' => false]) @empty <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-8 text-center text-sm text-stone-500">この日のご自身のレッスンはありません。</div> @endforelse</div></div>
    </div>
</section>

@php
    $procedures = [
        ['振替', 'レッスンの振替申請・履歴', route('student.transfer-requests.index')],
        ['お休み・遅刻', '確定予約について先生へ連絡', route('student.attendance-notices.index')],
        ['休会', '希望月の前月10日までに申請必須', route('student.membership-status-requests.index', ['type' => 'pause']).'#application-form'],
        ['退会', '希望月の前月10日までに申請必須', route('student.membership-status-requests.index', ['type' => 'withdraw']).'#application-form'],
        ['再開', '在籍に関する申請', route('student.membership-status-requests.index', ['type' => 'resume']).'#application-form'],
        ['時間割／曜日／時間変更', '曜日と開始時間', route('student.contract-change-requests.index', ['type' => 'schedule'])],
        ['月のレッスン回数変更', '変更希望月の前月10日までに申請必須', route('student.contract-change-requests.index', ['type' => 'monthly_lessons'])],
        ['レッスン時間変更', '1回あたりの時間', route('student.contract-change-requests.index', ['type' => 'lesson_minutes'])],
        ['コース変更', '現在のコースを変更', route('student.contract-change-requests.index', ['type' => 'course_change'])],
        ['コース追加', '新しいコースを追加', route('student.contract-change-requests.index', ['type' => 'course_add'])],
        ['会場変更', '登録済み会場から選択', route('student.contract-change-requests.index', ['type' => 'venue_change'])],
        ['レッスン種別変更', 'レギュラー／フレックス', route('student.contract-change-requests.index', ['type' => 'lesson_type'])],
        ['料金区分変更', '一般／ジュニア', route('student.contract-change-requests.index', ['type' => 'pricing_category'])],
        ['個人情報変更', '氏名・連絡先・住所', route('student.personal-information-change-requests.index')],
        ['支払い方法変更', '決済情報は保存しません', route('student.payment-method-change-requests.index')],
        ['ご請求', '請求内容・お支払い状況の確認', route('student.invoices.index')],
        ['その他問い合わせ', '教室へのお問い合わせ', route('student.inquiries.index')],
    ];
@endphp
<section id="student-procedures" class="mt-9 scroll-mt-6" aria-labelledby="procedures-title"><div><p class="text-sm font-semibold tracking-widest text-amber-700">PROCEDURES</p><h2 id="procedures-title" class="mt-1 text-2xl font-bold">各種お手続き</h2><p class="mt-2 text-sm text-stone-600">契約内容の変更や教室への連絡はこちらから申請できます。</p></div><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach ($procedures as [$title, $description, $url])<a href="{{ $url }}" class="group min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm transition hover:border-amber-300"><span class="flex items-center justify-between gap-3 font-semibold"><span>{{ $title }}</span><span class="text-amber-600 group-hover:translate-x-0.5">→</span></span><span class="mt-1 block text-xs font-normal text-stone-500">{{ $description }}</span></a>@endforeach</div></section>
@endsection
