@extends('layouts.app')

@section('title', 'ログイン | MUSAKOドラム教室')

@section('content')
    <div class="mx-auto grid max-w-4xl overflow-hidden rounded-3xl bg-white shadow-xl ring-1 ring-stone-200 md:grid-cols-2">
        <section class="bg-stone-900 p-7 text-white sm:p-10">
            <span class="grid size-14 place-items-center rounded-2xl bg-amber-400 text-3xl shadow-sm">🥁</span>
            <p class="mt-8 text-sm font-semibold tracking-[0.2em] text-amber-300">MUSAKO DRUM SCHOOL</p>
            <h1 class="mt-3 text-3xl font-bold leading-tight">レッスンを、<br>もっと身近に。</h1>
            <p class="mt-5 text-sm leading-7 text-stone-300">予約申請、確定した予定の確認、先生からのお知らせをひとつの場所で。</p>
        </section>

        <section class="p-7 sm:p-10">
            <h2 class="text-2xl font-bold">ログイン</h2>
            <p class="mt-2 text-sm text-stone-500">登録済みのメールアドレスを入力してください。</p>

            @if ($errors->any())
                <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('login') }}" class="mt-7 grid gap-5">
                @csrf
                <label class="grid gap-2 text-sm font-semibold">
                    メールアドレス
                    <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus class="min-h-12 rounded-xl border border-stone-300 px-4 font-normal outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                </label>
                <label class="grid gap-2 text-sm font-semibold">
                    パスワード
                    <input type="password" name="password" autocomplete="current-password" required class="min-h-12 rounded-xl border border-stone-300 px-4 font-normal outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200">
                </label>
                <label class="flex min-h-11 items-center gap-3 text-sm text-stone-600">
                    <input type="checkbox" name="remember" value="1" class="size-5 rounded border-stone-300 text-amber-500 focus:ring-amber-400">
                    ログイン状態を保持する
                </label>
                <button type="submit" class="min-h-12 rounded-xl bg-amber-400 px-5 font-bold text-stone-900 shadow-sm hover:bg-amber-300">ログイン</button>
            </form>
        </section>
    </div>
@endsection
