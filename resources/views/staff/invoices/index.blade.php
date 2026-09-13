@extends('layouts.app')
@section('title', '請求・入金管理 | MUSAKO')
@section('content')
<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div><p class="text-sm font-semibold tracking-widest text-amber-700">BILLING</p><h1 class="mt-1 text-2xl font-bold sm:text-4xl">請求・入金管理</h1><p class="mt-2 text-sm text-stone-600">下書きを確認してから確定し、入金状況を月単位で管理します。</p></div>
    <div class="flex items-center justify-between gap-2 rounded-xl border border-stone-200 bg-white p-1 shadow-sm"><a href="{{ route('staff.invoices.index', ['month' => $month->subMonth()->format('Y-m')]) }}" class="grid min-h-11 min-w-11 place-items-center rounded-lg hover:bg-stone-100">←</a><strong class="min-w-32 text-center">{{ $month->format('Y年 n月') }}</strong><a href="{{ route('staff.invoices.index', ['month' => $month->addMonth()->format('Y-m')]) }}" class="grid min-h-11 min-w-11 place-items-center rounded-lg hover:bg-stone-100">→</a></div>
</div>
@if ($errors->any())<div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif

<dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
    @foreach ([['対象生徒', $summary['students'].'人'], ['下書き', $summary['draft'].'件'], ['確定済み', $summary['confirmed'].'件'], ['未入金', $summary['unpaid'].'件'], ['一部入金', $summary['partial'].'件'], ['入金済み', $summary['paid'].'件'], ['期限超過', $summary['overdue'].'件'], ['請求総額', '¥'.number_format($summary['total'])], ['入金総額', '¥'.number_format($summary['received'])], ['未収金額', '¥'.number_format($summary['outstanding'])]] as [$label, $value])
        <div class="min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><dt class="text-xs text-stone-500">{{ $label }}</dt><dd class="mt-1 break-words text-xl font-bold">{{ $value }}</dd></div>
    @endforeach
</dl>

@if ($canManage)
<section class="mt-6 grid gap-4 lg:grid-cols-2">
    <form method="post" action="{{ route('staff.invoices.generate') }}" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">@csrf<h2 class="font-bold">月次請求の下書き生成</h2><p class="mt-1 text-xs text-stone-600">確定済み請求と手動調整済み下書きは変更しません。</p><div class="mt-4 flex flex-col gap-3 sm:flex-row"><input type="month" name="month" value="{{ $month->format('Y-m') }}" required class="min-h-11 min-w-0 flex-1 rounded-xl border-stone-300"><button class="min-h-11 rounded-xl bg-stone-900 px-5 font-semibold text-white">下書きを生成</button></div></form>
    <form method="post" action="{{ route('staff.billing-settings.update') }}" class="rounded-2xl border border-stone-200 bg-white p-5">@csrf @method('patch')<h2 class="font-bold">自動生成・支払期限設定</h2><div class="mt-4 grid gap-3 sm:grid-cols-2"><label class="text-sm">生成日（1〜28日）<input type="number" name="generation_day" min="1" max="28" value="{{ $setting->generation_day }}" class="mt-1 min-h-11 w-full rounded-xl border-stone-300"></label><label class="text-sm">生成時刻<input type="time" name="generation_time" value="{{ substr($setting->generation_time, 0, 5) }}" class="mt-1 min-h-11 w-full rounded-xl border-stone-300"></label><label class="text-sm">支払期限<select name="due_rule" class="mt-1 min-h-11 w-full rounded-xl border-stone-300"><option value="previous_month_end" @selected($setting->due_rule === 'previous_month_end')>対象月の前月末</option><option value="billing_month_day" @selected($setting->due_rule === 'billing_month_day')>対象月の指定日</option></select></label><label class="text-sm">指定日<input type="number" name="due_day" min="1" max="28" value="{{ $setting->due_day ?? 10 }}" class="mt-1 min-h-11 w-full rounded-xl border-stone-300"></label></div><div class="mt-3 flex flex-wrap gap-4 text-sm"><label><input type="checkbox" name="auto_generate_enabled" value="1" @checked($setting->auto_generate_enabled)> 自動生成</label><label><input type="checkbox" name="invoice_notifications_enabled" value="1" @checked($setting->invoice_notifications_enabled)> 請求通知</label><label><input type="checkbox" name="payment_notifications_enabled" value="1" @checked($setting->payment_notifications_enabled)> 入金通知</label></div><button class="mt-4 min-h-11 rounded-xl border border-stone-300 px-5 font-semibold">設定を保存</button></form>
</section>
@endif

<form method="get" class="mt-6 grid gap-3 rounded-2xl border border-stone-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6">
    <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="min-h-11 min-w-0 rounded-xl border-stone-300">
    <select name="student_profile_id" class="min-h-11 min-w-0 rounded-xl border-stone-300"><option value="">全生徒</option>@foreach ($students as $student)<option value="{{ $student->id }}" @selected((string) request('student_profile_id') === (string) $student->id)>{{ $student->user->name }}</option>@endforeach</select>
    <select name="status" class="min-h-11 min-w-0 rounded-xl border-stone-300"><option value="">全状態</option>@foreach (\App\Enums\MonthlyInvoiceStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select>
    <select name="payment_status" class="min-h-11 min-w-0 rounded-xl border-stone-300"><option value="">全入金状態</option>@foreach (\App\Enums\InvoicePaymentStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('payment_status') === $status->value)>{{ $status->label() }}</option>@endforeach</select>
    <label class="flex min-h-11 items-center gap-2 rounded-xl border border-stone-300 px-3 text-sm"><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))>期限超過のみ</label><button class="min-h-11 rounded-xl bg-stone-900 px-4 font-semibold text-white">絞り込む</button>
</form>

<div class="mt-5 grid gap-3">
@forelse ($invoices as $invoice)
    <a href="{{ route('staff.invoices.show', $invoice) }}" class="grid min-w-0 gap-3 rounded-2xl border {{ $invoice->is_overdue ? 'border-red-300 bg-red-50' : 'border-stone-200 bg-white' }} p-4 shadow-sm sm:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))] sm:items-center">
        <div class="min-w-0"><strong class="block truncate">{{ $invoice->studentProfile->user->name }}</strong><span class="text-xs text-stone-500">{{ $invoice->invoice_number ?? '番号未発行' }} · {{ $invoice->billing_month->format('Y年n月') }}</span></div>
        <div><span class="text-xs text-stone-500">請求額</span><strong class="block">¥{{ number_format($invoice->total_amount) }}</strong></div><div><span class="text-xs text-stone-500">入金</span><strong class="block">{{ $invoice->payment_status->label() }}</strong></div><div><span class="text-xs text-stone-500">期限</span><strong class="block">{{ $invoice->due_on?->format('n/j') ?? '未設定' }}{{ $invoice->is_overdue ? ' 超過' : '' }}</strong></div><div><span class="text-xs text-stone-500">状態</span><strong class="block">{{ $invoice->status->label() }}{{ $invoice->requires_review ? '・要確認' : '' }}</strong></div>
    </a>
@empty <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-10 text-center text-stone-500">この条件の請求はありません。</div>@endforelse
</div>
<div class="mt-5">{{ $invoices->links() }}</div>
@endsection
