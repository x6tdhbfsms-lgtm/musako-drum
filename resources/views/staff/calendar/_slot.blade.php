@php
    $slotLabel = ['open' => '受付中', 'closed' => '予約不可', 'cancelled' => '休講'][$slot->status->value];
    $slotStyle = ['open' => 'border-stone-200 bg-white', 'closed' => 'border-stone-300 bg-stone-100', 'cancelled' => 'border-red-200 bg-red-50'][$slot->status->value];
@endphp
<article class="min-w-0 rounded-xl border {{ $slotStyle }} {{ ($compact ?? false) ? 'p-2 text-[11px]' : 'p-4 text-sm shadow-sm' }}">
    <div class="flex items-start justify-between gap-2"><div class="min-w-0"><strong class="block {{ ($compact ?? false) ? '' : 'text-base' }}">{{ $slot->starts_at->format('H:i') }}〜{{ $slot->ends_at->format('H:i') }}</strong><span class="block truncate text-stone-600">{{ $slot->course?->name ?? 'コース未指定' }}</span></div><a href="{{ route('staff.lesson-slots.edit', $slot) }}" class="shrink-0 font-semibold text-stone-500" aria-label="レッスン枠を編集">編集</a></div>
    @unless ($compact ?? false)<p class="mt-1 text-xs text-stone-500">{{ $slot->teacherProfile->display_name }} · {{ $slot->venue?->name ?? '会場未定' }} · {{ $slotLabel }}</p>@endunless
    <div class="mt-2 grid gap-1.5">
        @forelse ($slot->reservationRequests as $reservation)
            @php($reservationStyle = ['pending' => 'bg-amber-100 text-amber-900', 'approved' => 'bg-emerald-100 text-emerald-900', 'rejected' => 'bg-red-100 text-red-800', 'cancelled' => 'bg-stone-200 text-stone-600'][$reservation->status->value])
            <a href="{{ route('staff.reservations.show', $reservation) }}" class="min-w-0 rounded-lg p-2 {{ $reservationStyle }}"><strong class="block truncate">{{ $reservation->studentProfile->user->name }}</strong><span class="mt-0.5 block">{{ ['pending' => '承認待ち', 'approved' => '予約確定', 'rejected' => '却下', 'cancelled' => 'キャンセル'][$reservation->status->value] }}</span>@if ($reservation->transferRequests->isNotEmpty() || $reservation->resultingTransferRequest)<span class="mt-1 inline-block rounded-full bg-violet-200 px-2 py-0.5 font-bold text-violet-900">振替</span>@endif @if ($reservation->attendanceNotice)<span class="mt-1 inline-block rounded-full {{ $reservation->attendanceNotice->type->value === 'absence' ? 'bg-red-200 text-red-900' : 'bg-orange-200 text-orange-900' }} px-2 py-0.5 font-bold">{{ $reservation->attendanceNotice->type->value === 'absence' ? '欠席' : '遅刻' }}</span>@endif</a>
        @empty
            <a href="{{ route('staff.lesson-slots.edit', $slot) }}" class="rounded-lg bg-stone-100 px-2 py-1.5 text-center font-semibold text-stone-500">{{ $slotLabel }} · 予約なし</a>
        @endforelse
    </div>
</article>
