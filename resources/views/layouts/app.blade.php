<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'MUSAKOドラム教室')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white/95 shadow-sm backdrop-blur">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <a href="/" class="flex items-center gap-3">
                <span class="grid size-10 place-items-center rounded-2xl bg-amber-400 text-xl shadow-sm">🥁</span>
                <span><strong class="block text-sm tracking-wide">MUSAKO</strong><span class="text-xs text-stone-500">ドラム教室</span></span>
            </a>
            @auth
                <nav class="flex flex-wrap items-center justify-end gap-2 text-sm">
                    <span class="rounded-full bg-stone-100 px-3 py-2 text-xs font-semibold">{{ ['admin' => '管理者', 'teacher' => '講師', 'student' => '生徒'][auth()->user()->role->value] }}：{{ auth()->user()->role->value === 'teacher' ? (auth()->user()->teacherProfile?->display_name ?? auth()->user()->name) : auth()->user()->name }}</span>
                    @if (auth()->user()->role->value === 'student')
                        <a href="{{ route('student.dashboard') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">カレンダー</a>
                        <a href="{{ route('student.lesson-slots.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">空き枠</a>
                        <a href="{{ route('student.reservations.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">予約一覧</a>
                        <a href="{{ route('student.attendance-notices.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">お休み・遅刻</a>
                        <a href="{{ route('student.transfer-requests.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">振替</a>
                        <a href="{{ route('student.contract-change-requests.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">各種手続き</a>
                        <a href="{{ route('student.invoices.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">ご請求</a>
                    @else
                        <a href="{{ route('staff.dashboard') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">Home</a>
                        <a href="{{ route('staff.lesson-slots.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">レッスン一覧</a>
                        <details class="group relative">
                            <summary class="cursor-pointer list-none rounded-full px-3 py-2 hover:bg-stone-100">申請管理 <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute right-0 z-50 mt-2 grid min-w-44 gap-1 rounded-2xl border border-stone-200 bg-white p-2 text-sm shadow-xl">
                                <a href="{{ route('staff.reservations.index') }}" class="rounded-xl px-3 py-2 hover:bg-stone-100">予約申請</a>
                                <a href="{{ route('staff.transfer-requests.index') }}" class="rounded-xl px-3 py-2 hover:bg-stone-100">振替</a>
                                <a href="{{ route('staff.transfer-requests.deferred') }}" class="rounded-xl px-3 py-2 hover:bg-stone-100">保留レッスン</a>
                            </div>
                        </details>
                        <details class="group relative">
                            <summary class="cursor-pointer list-none rounded-full px-3 py-2 hover:bg-stone-100">各種手続き <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute right-0 z-50 mt-2 grid min-w-44 gap-1 rounded-2xl border border-stone-200 bg-white p-2 text-sm shadow-xl">
                                <a href="{{ route('staff.procedure-requests.index') }}" class="rounded-xl px-3 py-2 hover:bg-stone-100">契約・情報変更</a>
                                <a href="{{ route('staff.membership-status-requests.index') }}" class="rounded-xl px-3 py-2 hover:bg-stone-100">休会・退会・再開</a>
                            </div>
                        </details>
                        <a href="{{ route('staff.students.index') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">生徒</a>
                        <details class="group relative">
                            <summary class="cursor-pointer list-none rounded-full px-3 py-2 hover:bg-stone-100">料金・請求 <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute right-0 z-50 mt-2 grid min-w-44 gap-1 rounded-2xl border border-stone-200 bg-white p-2 text-sm shadow-xl">
                                <a href="{{ route('staff.pricing-settings.index') }}" class="rounded-xl px-3 py-2 hover:bg-stone-100">料金設定</a>
                                <a href="{{ route('staff.invoices.index') }}" class="rounded-xl px-3 py-2 hover:bg-stone-100">請求管理</a>
                            </div>
                        </details>
                    @endif
                    @if (auth()->user()->role === App\Enums\UserRole::Admin)
                    <a href="{{ route('login') }}" target="_blank" rel="noopener" class="rounded-full px-3 py-2 hover:bg-stone-100">別の区分でログイン ↗</a>
                    @endif
                    <form method="post" action="{{ route('logout') }}">@csrf<input type="hidden" name="portal" value="{{ auth()->user()->role->value === 'student' ? 'student' : 'staff' }}"><button class="rounded-full border border-stone-300 px-3 py-2 hover:bg-stone-100">ログアウト</button></form>
                </nav>
            @endauth
            @guest
                @unless (request()->routeIs('login'))
                <nav class="flex items-center gap-2 text-sm"><a href="{{ route('trial-lessons.index') }}" class="rounded-full bg-amber-400 px-4 py-2 font-bold">体験レッスン</a><a href="{{ route('pricing') }}" class="rounded-full px-3 py-2 hover:bg-stone-100">料金</a><a href="{{ route('login') }}" class="rounded-full border border-stone-300 px-3 py-2">ログイン</a></nav>
                @endunless
            @endguest
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
        @if (session('success'))
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif
        @yield('content')
    </main>
</body>
</html>
