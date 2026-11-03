<label class="flex min-w-0 cursor-pointer gap-2 rounded-lg border border-stone-200 bg-amber-50 p-2 has-checked:border-amber-500 has-checked:ring-2 has-checked:ring-amber-200">
    <input type="radio" name="requested_starts_at" value="{{ $slot->starts_at->format('Y-m-d H:i') }}" data-slot-time="{{ $slot->starts_at->format('Y年n月j日') }}（{{ ['日','月','火','水','木','金','土'][$slot->starts_at->dayOfWeek] }}） {{ $slot->starts_at->format('H:i') }}〜{{ $slot->ends_at->format('H:i') }}" data-slot-venue="{{ $slot->venue?->name ?? '会場未定' }}" data-slot-summary="{{ $slot->starts_at->format('n月j日 H:i') }}〜{{ $slot->ends_at->format('H:i') }} ／ {{ $slot->venue?->name ?? '会場未定' }}" required @checked(old('requested_starts_at') === $slot->starts_at->format('Y-m-d H:i')) class="mt-1 size-5 shrink-0">
    <span class="min-w-0 break-words">
        <strong class="block text-xs">{{ $slot->starts_at->format('H:i') }}〜@if ($slot->venue?->termsRoomType() !== 'studio34'){{ $slot->ends_at->format('H:i') }}@endif</strong>
        <span class="mt-1 block text-xs">{{ $slot->venue?->name ?? '会場未定' }}</span>
        <span class="mt-1 block text-xs text-stone-500">{{ $slot->course?->name ?? 'コース指定なし' }} · 残り{{ $slot->capacity - $slot->approved_reservations_count }}名</span>
    </span>
</label>
