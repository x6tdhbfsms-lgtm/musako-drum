<section class="mt-8 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="student-pricing-title">
    <div class="flex flex-wrap items-end justify-between gap-2"><div><p class="text-xs font-semibold tracking-widest text-amber-700">CURRENT PRICING</p><h2 id="student-pricing-title" class="mt-1 text-xl font-bold">契約料金目安</h2></div><a href="{{ route('staff.pricing-settings.index') }}" class="text-sm font-semibold text-amber-700">料金設定を確認 →</a></div>
    @if ($hasInactiveFlexWarning)<p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">2ヶ月以上レッスンがありません。</p>@endif
    <div class="mt-5 grid gap-3 lg:grid-cols-2">
        @forelse ($currentEnrollments as $enrollment)
            @php
                $quote = $pricingQuotes[$enrollment->id];
            @endphp
            <article class="min-w-0 rounded-xl bg-stone-50 p-4"><div class="flex flex-wrap justify-between gap-2"><strong>{{ $enrollment->course->name }}</strong><span class="text-xs font-bold">{{ $enrollment->lesson_type->label() }}・{{ $enrollment->pricing_category->label() }}</span></div><p class="mt-2 text-sm text-stone-600">月{{ $enrollment->monthly_lesson_limit }}回</p>@if ($quote->isConsultationRequired)<p class="mt-3 font-semibold text-amber-800">料金は要相談</p>@else<dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-stone-500">レッスン料金</dt><dd class="font-bold">¥{{ number_format($quote->lessonFeeTotal) }}</dd></div><div><dt class="text-stone-500">スタジオ / 回</dt><dd class="font-bold">{{ $quote->studioFeePerLesson === null ? '要確認' : '¥'.number_format($quote->studioFeePerLesson) }}</dd></div><div class="col-span-2"><dt class="text-stone-500">月額目安</dt><dd class="text-xl font-bold">{{ $quote->estimatedMonthlyTotal === null ? '要確認' : '¥'.number_format($quote->estimatedMonthlyTotal) }}</dd></div></dl>@endif</article>
        @empty
            <p class="rounded-xl border border-dashed border-stone-300 p-5 text-sm text-stone-500 lg:col-span-2">現在有効な契約がありません。</p>
        @endforelse
    </div>
</section>
