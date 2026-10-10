@php
    $isRegular = $slot instanceof \App\Models\RegularScheduleOccurrence;
    $reservation = $isRegular ? null : $slot->reservationRequests->first();
    $name = $isRegular ? $slot->studentProfile->user->name : ($reservation?->studentProfile?->user?->name ?? '予約なし');
    $status = $isRegular
        ? ($slot->status->value === 'conflict' ? '要調整' : '仮押さえ')
        : ($reservation ? ['pending' => '承認待ち', 'approved' => '確定', 'rejected' => '却下', 'cancelled' => 'キャンセル'][$reservation->status->value] : ['open' => '受付中', 'closed' => '予約不可', 'cancelled' => '休講'][$slot->status->value]);
    $url = $isRegular ? route('staff.regular-schedules.index', ['month' => $slot->lesson_entitlement_month->format('Y-m')]) : ($reservation ? route('staff.reservations.show', $reservation) : route('staff.lesson-slots.edit', $slot));
    $description = $slot->starts_at->format('H:i').'〜'.$slot->ends_at->format('H:i').' '.$name.' · '.($slot->venue?->name ?? '会場未定').' · '.$status;
@endphp
<a href="{{ $url }}" title="{{ $description }}" aria-label="{{ $description }}" class="flex min-h-7 min-w-0 items-center gap-1 rounded border-l-2 px-1.5 py-1 text-[11px] leading-4 hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-1 {{ $slot->venue?->termsRoomType() === 'music_room' ? 'border-sky-500 bg-sky-50 text-sky-950' : 'border-amber-500 bg-amber-50 text-amber-950' }}">
    <strong class="shrink-0 tabular-nums">{{ $slot->starts_at->format('H:i') }}</strong>
    <span class="min-w-0 flex-1 truncate">{{ $name }}</span>
    @if ($isRegular || ($reservation && $reservation->status->value !== 'approved'))<span class="shrink-0 text-[10px] font-bold">{{ $status }}</span>@endif
    @if ($reservation?->attendanceNotice)<span class="shrink-0 font-bold text-red-700">{{ $reservation->attendanceNotice->type->value === 'absence' ? '欠' : '遅' }}</span>@endif
    @if (! $isRegular && $slot->reservationRequests->count() > 1)<span class="shrink-0">+{{ $slot->reservationRequests->count() - 1 }}</span>@endif
</a>
