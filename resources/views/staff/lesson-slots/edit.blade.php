@extends('layouts.app')
@section('title', 'レッスン枠編集 | MUSAKO')
@section('content')
<div class="mx-auto max-w-3xl"><p class="text-sm font-semibold text-amber-700">EDIT SLOT</p><h1 class="text-2xl font-bold sm:text-3xl">レッスン枠を編集</h1><form method="post" action="{{ route('staff.lesson-slots.update', $lessonSlot) }}" class="mt-7 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7">@csrf @method('put') @include('staff.lesson-slots._form', ['submitLabel' => '更新する'])</form></div>
@endsection
