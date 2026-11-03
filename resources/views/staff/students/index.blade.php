@extends('layouts.app')
@section('title', '生徒一覧 | MUSAKO')
@section('content')
@if (auth()->user()->role->value === 'admin')
    <a href="{{ route('staff.admission-applications.create') }}" class="mb-4 inline-flex min-h-10 items-center rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold">＋ 新規生徒登録</a>
@endif
<div>
    <p class="text-xs font-semibold tracking-widest text-amber-700">STUDENTS</p>
    <h1 class="mt-1 text-2xl font-bold">生徒一覧</h1>
</div>
@php
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $accountStatusLabels = ['active' => '在籍', 'suspended' => '休止', 'withdrawn' => '退会'];
@endphp
@php
    $sections = [
        'electronic_drum' => '電子ドラムコース',
        'studio34' => 'studio34',
        'other' => 'その他・契約情報なし',
    ];
@endphp
<div class="mt-4 space-y-5">
    @if ($students->isEmpty())
        <p class="rounded-xl border border-dashed border-stone-300 bg-white p-6 text-center text-sm text-stone-500">表示できる生徒はいません。</p>
    @else
        @foreach ($studentsByCourse as $group => $groupStudents)
            @if ($groupStudents->isNotEmpty())
            <section aria-labelledby="students-{{ $group }}">
                <div class="mb-2 flex items-center gap-2">
                    <h2 id="students-{{ $group }}" class="text-sm font-bold text-stone-800">{{ $sections[$group] }}</h2>
                    <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600">{{ $groupStudents->count() }}名</span>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($groupStudents as $student)
                        @php
                            $currentEnrollments = $student->enrollments->filter(fn ($enrollment) => ! $enrollment->starts_on->isAfter(today(config('app.timezone'))));
                            $futureEnrollments = $student->enrollments->filter(fn ($enrollment) => $enrollment->starts_on->isAfter(today(config('app.timezone'))));
                        @endphp
                        <a href="{{ route('staff.students.show', $student) }}" class="grid min-w-0 gap-2 rounded-xl border border-stone-200 bg-white px-3 py-2 shadow-sm hover:border-amber-300 sm:grid-cols-[minmax(8rem,0.8fr)_minmax(0,1.4fr)] sm:items-center">
                            <div class="flex min-w-0 items-center justify-between gap-2 sm:block">
                                <strong class="truncate text-sm">{{ $student->user->name }}</strong>
                                <span class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600">{{ $accountStatusLabels[$student->user->account_status->value] ?? $student->user->account_status->value }}</span>
                            </div>
                            <div class="min-w-0 text-xs leading-5 text-stone-700">
                                @if ($currentEnrollments->isEmpty() && $futureEnrollments->isEmpty())
                                    <p class="text-stone-500">現在有効な契約情報はありません。</p>
                                @endif
                                @foreach ($currentEnrollments as $enrollment)
                                    @php
                                        $weeks = $enrollment->regular_week_numbers ?: ($enrollment->monthly_lesson_limit === 2 ? [1, 3] : ($enrollment->monthly_lesson_limit > 0 ? range(1, min(4, $enrollment->monthly_lesson_limit)) : []));
                                        $schedule = $enrollment->lesson_type === App\Enums\LessonType::Flex
                                            ? 'フレックス'
                                            : ($enrollment->weekday === null
                                                ? '曜日未設定'
                                                : ($weeks === [] ? $weekdays[$enrollment->weekday].'曜日（週未設定）' : '第'.implode('・第', $weeks).$weekdays[$enrollment->weekday].'曜日'));
                                    @endphp
                                    <p class="truncate">{{ $schedule }}</p>
                                @endforeach
                                @foreach ($futureEnrollments as $enrollment)
                                    @php
                                        $weeks = $enrollment->regular_week_numbers ?: ($enrollment->monthly_lesson_limit === 2 ? [1, 3] : ($enrollment->monthly_lesson_limit > 0 ? range(1, min(4, $enrollment->monthly_lesson_limit)) : []));
                                        $schedule = $enrollment->lesson_type === App\Enums\LessonType::Flex
                                            ? 'フレックス'
                                            : ($enrollment->weekday === null
                                                ? '曜日未設定'
                                                : ($weeks === [] ? $weekdays[$enrollment->weekday].'曜日（週未設定）' : '第'.implode('・第', $weeks).$weekdays[$enrollment->weekday].'曜日'));
                                    @endphp
                                    <p class="truncate text-amber-800">{{ $enrollment->starts_on->format('n月j日開始') }} · {{ $schedule }}</p>
                                @endforeach
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
            @endif
        @endforeach
    @endif
</div>
<div class="mt-4">{{ $students->links() }}</div>
@endsection
