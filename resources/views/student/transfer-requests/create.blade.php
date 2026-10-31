@extends('layouts.app')
@section('title', '振替先を選択 | MUSAKO')
@section('content')
@php
    $today = \Carbon\CarbonImmutable::now(config('app.timezone'));
    $firstWeek = $today->startOfWeek(\Carbon\CarbonInterface::MONDAY);
    $lastWeek = $today->addMonthNoOverflow()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
    $weeks = collect();
    for ($week = $firstWeek; $week->lte($lastWeek); $week = $week->addWeek()) {
        $weeks->push($week);
    }
    $slotsByTime = $availableSlots->groupBy(fn ($slot) => $slot->starts_at->format('Y-m-d-H:i'));
    $timeStep = $availableSlots->contains(fn ($slot) => $slot->starts_at->minute % 30 !== 0) ? 15 : 30;
    $weekdayNames = ['日', '月', '火', '水', '木', '金', '土'];
@endphp
<div data-transfer-calendar>
    <h1 class="text-2xl font-bold sm:text-3xl">週間の空き枠から振替先を選択</h1>
    <p class="mt-2 text-sm text-stone-600">希望の日時の空き枠を選び、会場を確認してください。会場が変わる場合は、講師の移動時間15分を確保できる枠だけ表示されます。正式な申請期限は元レッスン前日の12:00です。生徒様の送信は希望受付となり、講師が確定した日時で期限・追加料金を判定します。</p>
<aside class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p>レッスン予定日より4日を切ってのご申請は、振替料金1,000円が発生します（電子ドラムコースの前日申請は、現在の規約では追加なし）。</p><p class="mt-2 font-semibold">前日のお昼12:00以降は通常の振替申請ができません。</p><p class="mt-2">料金・期限は講師が正式に確定した日時で判定します。自然災害などの特例は先生へご相談ください。</p></aside>
    <p class="mt-3 text-sm">このレッスンは{{ $reservationRequest->lessonSlot->starts_at->copy()->subDays(3)->format('n月j日') }}から直前振替料金の対象です。電子ドラムの前日申請は除きます。</p>
    <p class="mt-3 text-sm font-semibold">このレッスンの通常申請受付：{{ $reservationRequest->transferRequestDeadline()->format('Y年n月j日 H:i') }}より前まで</p>
    @if (now()->greaterThanOrEqualTo($reservationRequest->transferRequestDeadline()))<p role="alert" class="mt-2 text-sm font-bold text-red-700">通常の申請期限を過ぎています。特例のご相談は先生の確認が必要です。</p>@endif
    @if ($errors->any())
        <div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif
    <section class="mt-6 rounded-2xl bg-stone-900 p-5 text-white">
        <span class="text-xs text-stone-300">元の予約</span>
        <h2 class="mt-1 text-lg font-bold">{{ $reservationRequest->lessonSlot->starts_at->format('Y年n月j日 H:i') }}〜{{ $reservationRequest->lessonSlot->ends_at->format('H:i') }}</h2>
        <p class="mt-2 text-sm">{{ $reservationRequest->lessonSlot->venue?->name ?? '会場未定' }}</p>
        <p class="mt-2 text-sm text-stone-300">申請期限 {{ $reservationRequest->transferRequestDeadline()->format('Y年n月j日 H:i') }}</p>
    </section>
    <form method="post" action="{{ route(auth()->user()->role->value === 'student' ? 'student.transfer-requests.store' : 'staff.transfer-requests.store', $reservationRequest) }}" class="mt-6 grid gap-5">
        @csrf
        <section class="rounded-2xl border border-violet-200 bg-violet-50 p-4">
            <h2 class="font-bold text-violet-900">希望の日が空いていない・まだ日程が決まらない場合</h2>
            <p class="mt-2 text-sm text-stone-700">日付を選ばず保留を申請できます。先生の承認後に元の予約が外れ、日程保留中になります。翌月以降の日程も先生が後から設定できます。休会・退会月以降への繰越はできません。</p>
            <button name="defer_schedule" value="1" formnovalidate class="mt-3 min-h-12 rounded-xl bg-violet-700 px-5 font-bold text-white">振替日を保留して申請する</button>
            <p class="mt-2 text-xs text-stone-600">期限・追加料金は先生が保留を承認した日時で判定します。理由・備考は下の欄に入力できます。</p>
        </section>
        <fieldset class="min-w-0">
            <legend class="font-bold">予約可能な振替先</legend>
            <nav data-week-controls hidden aria-label="表示する週を変更" class="mt-4 flex items-center justify-between gap-2">
                <button type="button" data-week-prev class="min-h-11 shrink-0 rounded-xl border border-stone-300 px-3 disabled:opacity-40">前の週</button>
                <span data-week-heading role="status" class="text-center text-sm font-semibold"></span>
                <button type="button" data-week-next class="min-h-11 shrink-0 rounded-xl border border-stone-300 px-3 disabled:opacity-40">次の週</button>
            </nav>
            <p class="mt-3 text-xs text-stone-600">本日から1か月先まで、平日12:00〜21:00・土日10:00〜20:00の空き時間を、電子ドラムは15分刻み、studio34は30分刻みで表示しています。同じ会場なら続けて予約できます。「—」は選択できる枠がありません。表の中を上下左右にスクロールできます。日付と曜日は上部に固定されます。</p>
            @if ($availableSlots->isEmpty())
                <p class="mt-3 rounded-xl border border-dashed border-stone-300 p-4 text-stone-500">現在選択できる振替先がありません。</p>
            @endif
            @foreach ($weeks as $week)
                <section data-transfer-week="{{ $week->format('Y-m-d') }}" data-week-title="{{ $week->format('Y年n月j日') }}〜{{ $week->addDays(6)->format('n月j日') }}" class="mt-4">
                    <div class="max-h-[70dvh] overflow-auto rounded-xl border border-stone-200" tabindex="0" role="region" aria-label="{{ $week->format('n月j日') }}から1週間の空き枠">
                        <table class="w-full min-w-[1000px] table-fixed border-separate border-spacing-0 text-center text-sm">
                            <caption class="bg-white p-3 font-bold">{{ $week->format('Y年n月j日') }}〜{{ $week->addDays(6)->format('n月j日') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="sticky top-0 left-0 z-30 w-16 border-b border-r border-stone-200 bg-stone-100 p-2">時間</th>
                                    @for ($offset = 0; $offset < 7; $offset++)
                                        @php $date = $week->addDays($offset); @endphp
                                        <th scope="col" @class(['sticky top-0 z-20 border-b border-r border-stone-200 p-3', 'bg-amber-100' => $date->isSameDay($today), 'bg-stone-100' => ! $date->isSameDay($today), 'text-red-700' => $date->isSunday(), 'text-blue-700' => $date->isSaturday()])>
                                            {{ $date->format('n/j') }}（{{ $weekdayNames[$date->dayOfWeek] }}）
                                        </th>
                                    @endfor
                                </tr>
                            </thead>
                            <tbody>
                                @for ($minuteOfDay = 10 * 60; $minuteOfDay < 21 * 60; $minuteOfDay += $timeStep)
                                    @php $hour = intdiv($minuteOfDay, 60); $minute = $minuteOfDay % 60; $timeLabel = sprintf('%02d:%02d', $hour, $minute); @endphp
                                    <tr>
                                        <th scope="row" class="sticky left-0 z-10 border-b border-r border-stone-200 bg-stone-100 p-2 align-top">{{ $timeLabel }}</th>
                                        @for ($offset = 0; $offset < 7; $offset++)
                                            @php $slots = $slotsByTime->get($week->addDays($offset)->format('Y-m-d').'-'.$timeLabel, collect()); @endphp
                                            <td class="border-b border-r border-stone-200 p-1 align-top {{ \App\Services\LessonOperatingHours::allows($week->addDays($offset)->setTime($hour, $minute), $week->addDays($offset)->setTime($hour, $minute)->addMinutes($timeStep)) ? 'bg-white' : 'bg-stone-100' }}">
                                                <div class="grid min-h-12 gap-2 text-left">
                                                    @forelse ($slots as $slot)
                                                        @include('student.transfer-requests.slot-option', ['slot' => $slot])
                                                    @empty
                                                        <span class="self-center text-center text-stone-400" aria-label="選択できる空き枠なし">—</span>
                                                    @endforelse
                                                </div>
                                            </td>
                                        @endfor
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>
                    @php
                        $otherSlots = $availableSlots->filter(fn ($slot) => $slot->starts_at->gte($week) && $slot->starts_at->lt($week->addWeek()) && ($slot->starts_at->hour < 10 || $slot->starts_at->hour > 21));
                    @endphp
                    @if ($otherSlots->isNotEmpty())
                        <h3 class="mt-4 text-sm font-semibold">この週のその他の時間帯</h3>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($otherSlots as $slot)
                                <div>
                                    <p class="mb-1 text-sm">{{ $slot->starts_at->format('n月j日') }}</p>
                                    @include('student.transfer-requests.slot-option', ['slot' => $slot])
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </fieldset>
        <p data-transfer-selection role="status" class="rounded-xl bg-amber-50 p-4 text-sm">週間の表から希望の空き枠を選択してください。</p>
        <label class="flex items-start gap-2 rounded-xl bg-stone-100 p-4 text-sm"><input type="checkbox" name="special_handling" value="1" @checked(old('special_handling'))>災害・交通機関の事故・講師都合等による特例を希望（理由必須。講師による確認が必要です）</label>
        <label class="grid gap-2 text-sm font-semibold"><span data-transfer-reason-label>理由（通常は任意・特例を希望する場合は必須）</span><textarea name="reason" rows="3" maxlength="1000" @required(old('special_handling')) class="rounded-xl border border-stone-300 p-3 font-normal">{{ old('reason') }}</textarea></label>
        @error('reason')<p role="alert" class="text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
        <label class="grid gap-2 text-sm font-semibold">備考（任意）<textarea name="student_note" rows="3" maxlength="1000" class="rounded-xl border border-stone-300 p-3 font-normal">{{ old('student_note') }}</textarea></label>
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route(auth()->user()->role->value === 'student' ? 'student.reservations.index' : 'staff.reservations.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-stone-300 px-5 font-semibold">戻る</a>
            <button data-transfer-submit @disabled($availableSlots->isEmpty()) class="min-h-12 rounded-xl bg-amber-400 px-6 font-bold text-stone-900 disabled:cursor-not-allowed disabled:bg-stone-200">振替を申請する</button>
        </div>
    </form>
</div>
@endsection
