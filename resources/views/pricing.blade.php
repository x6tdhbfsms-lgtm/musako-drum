@extends('layouts.app')
@section('title', '料金のご案内 | MUSAKO')
@section('content')
@php
    $mode = $setting?->display_mode;
@endphp
<div class="mx-auto max-w-5xl">
    <div class="rounded-3xl bg-stone-900 px-5 py-9 text-white sm:px-10 sm:py-12"><p class="text-sm font-semibold tracking-widest text-amber-300">PRICING GUIDE</p><h1 class="mt-2 text-3xl font-bold sm:text-5xl">料金のご案内</h1><p class="mt-4 max-w-2xl text-sm leading-7 text-stone-300">{{ $setting?->pricing_notice ?? '最新の料金・詳細については教室へお問い合わせください。' }}</p></div>

    @if ($mode?->value === 'external_only')
        <section class="mt-7 rounded-2xl border border-stone-200 bg-white p-6 text-center shadow-sm sm:p-10"><h2 class="text-xl font-bold">最新料金は公式ページでご確認ください</h2><p class="mt-3 text-sm text-stone-600">料金は変更になる場合があります。詳しい条件と最新情報をご案内しています。</p></section>
    @else
        <section class="mt-7 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-semibold tracking-widest text-amber-700">REGULAR LESSON</p><h2 class="mt-1 text-2xl font-bold">レギュラーレッスン</h2></div><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">{{ $mode?->label() ?? '料金目安' }}・税込</span></div>
            <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[32rem] text-left text-sm"><thead><tr class="border-b border-stone-200 text-stone-500"><th class="p-3">月回数</th><th class="p-3">一般</th><th class="p-3">ジュニア</th></tr></thead><tbody>
                @foreach (range(1, 4) as $count)
                    <tr class="border-b border-stone-100"><th class="p-3">月{{ $count }}回</th>
                        @foreach (App\Enums\PricingCategory::cases() as $category)
                            @php
                                $quote = $quotes[$category->value][$count];
                            @endphp
                            <td class="p-3 font-semibold">{{ $quote->isConsultationRequired ? '要相談' : '¥'.number_format($quote->lessonFeeTotal) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody></table></div>
        </section>

        <div class="mt-5 grid gap-5 md:grid-cols-3">
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-widest text-violet-700">FLEX</p><h2 class="mt-1 text-lg font-bold">フレックスレッスン</h2><p class="mt-3 text-sm text-stone-600">同じ月回数のレギュラー料金に加算</p><strong class="mt-3 block text-2xl">＋¥{{ number_format($flexQuote->flexSurcharge) }}</strong></section>
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-widest text-emerald-700">STUDIO</p><h2 class="mt-1 text-lg font-bold">スタジオ使用料</h2><p class="mt-3 text-sm text-stone-600">1レッスン・税込</p><strong class="mt-3 block text-2xl">{{ $flexQuote->studioFeePerLesson === null ? '要確認' : '¥'.number_format($flexQuote->studioFeePerLesson) }}</strong></section>
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><p class="text-xs font-semibold tracking-widest text-amber-700">ADMISSION</p><h2 class="mt-1 text-lg font-bold">入会金</h2>@if ($setting?->admission_campaign_enabled)<p class="mt-3 text-sm font-semibold text-amber-800">{{ $setting->admission_campaign_message }}</p><strong class="mt-3 block text-2xl">{{ $setting->admission_campaign_fee === null ? '要確認' : '¥'.number_format($setting->admission_campaign_fee) }}</strong>@else<strong class="mt-3 block text-2xl">{{ $setting?->normal_admission_fee === null ? '要確認' : '¥'.number_format($setting->normal_admission_fee) }}</strong>@endif</section>
        </div>
    @endif

    @if ($setting?->lesson_pricing_url || $setting?->studio_pricing_url)<div class="mt-7 flex flex-col gap-3 sm:flex-row">@if ($setting->lesson_pricing_url)<a href="{{ $setting->lesson_pricing_url }}" rel="noopener noreferrer" target="_blank" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-stone-900 px-5 text-sm font-bold text-white">最新のレッスン料金を確認 ↗</a>@endif @if ($setting->studio_pricing_url)<a href="{{ $setting->studio_pricing_url }}" rel="noopener noreferrer" target="_blank" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-stone-300 bg-white px-5 text-sm font-bold">最新のスタジオ料金を確認 ↗</a>@endif</div>@endif
    <p class="mt-6 text-xs leading-6 text-stone-500">表示金額はすべて税込の目安です。契約内容や利用回数により異なる場合があります。</p>
</div>
@endsection
