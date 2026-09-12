@extends('layouts.app')

@section('title', 'お休み・遅刻連絡 | MUSAKO')

@section('content')
    <div>
        <p class="text-sm font-semibold text-amber-700">ATTENDANCE</p>
        <h1 class="text-2xl font-bold sm:text-3xl">お休み・遅刻連絡</h1>
        <p class="mt-2 text-sm text-stone-600">確定済みのレッスンについて、欠席または遅刻を先生へ連絡できます。</p>
    </div>

    @if ($errors->any())
        <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    <div class="mt-7 grid gap-5">
        @forelse ($reservations as $reservation)
            @php($notice = $reservation->attendanceNotice)
            <article class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:p-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold">{{ $reservation->lessonSlot->starts_at->format('Y年n月j日 H:i') }}〜{{ $reservation->lessonSlot->ends_at->format('H:i') }}</h2>
                        <p class="mt-1 text-sm text-stone-600">{{ $reservation->lessonSlot->teacherProfile->display_name }} · {{ $reservation->lessonSlot->venue?->name ?? '会場未定' }}</p>
                    </div>
                    @if ($notice)
                        <span class="self-start rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">連絡済み・更新可能</span>
                    @endif
                </div>

                <form method="post" action="{{ route('student.attendance-notices.store', $reservation) }}" class="mt-5 grid gap-4">
                    @csrf
                    @method('put')
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-2 text-sm font-semibold">連絡内容
                            <select name="type" required class="min-h-12 rounded-xl border border-stone-300 bg-white px-3">
                                <option value="absence" @selected(old('type', $notice?->type->value) === 'absence')>お休み</option>
                                <option value="late" @selected(old('type', $notice?->type->value) === 'late')>遅刻</option>
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm font-semibold">遅刻予定分数
                            <input type="number" name="late_minutes" min="1" max="180" value="{{ old('late_minutes', $notice?->late_minutes) }}" placeholder="例：15" class="min-h-12 rounded-xl border border-stone-300 px-3 font-normal">
                        </label>
                        <label class="grid gap-2 text-sm font-semibold">到着予定時刻
                            <input type="time" name="expected_arrival_time" value="{{ old('expected_arrival_time', $notice?->expected_arrival_at?->format('H:i')) }}" class="min-h-12 rounded-xl border border-stone-300 px-3 font-normal">
                        </label>
                        <label class="grid gap-2 text-sm font-semibold">備考（任意）
                            <input name="notes" maxlength="1000" value="{{ old('notes', $notice?->notes) }}" class="min-h-12 rounded-xl border border-stone-300 px-3 font-normal">
                        </label>
                    </div>
                    <p class="text-xs leading-5 text-stone-500">遅刻の場合は、予定分数または到着予定時刻のどちらかを入力してください。</p>
                    <button class="min-h-12 rounded-xl bg-stone-900 px-5 font-semibold text-white sm:justify-self-end">{{ $notice ? '連絡を更新する' : '先生へ連絡する' }}</button>
                </form>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-10 text-center text-stone-500">連絡できる確定済み予約はありません。</div>
        @endforelse
    </div>
@endsection
