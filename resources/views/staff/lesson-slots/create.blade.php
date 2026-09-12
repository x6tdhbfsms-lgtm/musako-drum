@extends('layouts.app')
@section('title', 'レッスン枠作成 | MUSAKO')
@section('content')
<div class="mx-auto max-w-3xl"><p class="text-sm font-semibold text-amber-700">NEW SLOT</p><h1 class="text-2xl font-bold sm:text-3xl">レッスン枠を作成</h1><form method="post" action="{{ route('staff.lesson-slots.store') }}" class="mt-7 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7">@csrf @include('staff.lesson-slots._form', ['lessonSlot' => null, 'submitLabel' => '作成する', 'suggestedStartsAt' => $suggestedStartsAt, 'suggestedEndsAt' => $suggestedEndsAt])</form></div>
@endsection
