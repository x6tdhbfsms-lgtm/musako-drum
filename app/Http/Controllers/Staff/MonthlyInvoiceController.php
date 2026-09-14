<?php

namespace App\Http\Controllers\Staff;

use App\Actions\AddInvoiceAdjustment;
use App\Actions\CancelMonthlyInvoice;
use App\Actions\ConfirmMonthlyInvoice;
use App\Actions\RegisterInvoicePayment;
use App\Actions\ReissueMonthlyInvoice;
use App\Enums\InvoicePaymentStatus;
use App\Enums\MonthlyInvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterInvoicePaymentRequest;
use App\Models\BillingSetting;
use App\Models\MonthlyInvoice;
use App\Models\StudentProfile;
use App\Services\MonthlyInvoiceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MonthlyInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MonthlyInvoice::class);
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'student_profile_id' => ['nullable', 'integer', 'exists:student_profiles,id'],
            'status' => ['nullable', Rule::enum(MonthlyInvoiceStatus::class)],
            'payment_status' => ['nullable', Rule::enum(InvoicePaymentStatus::class)],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'), config('app.timezone'));
        $query = MonthlyInvoice::query()
            ->with('studentProfile.user')
            ->whereDate('billing_month', $month)
            ->when(isset($validated['student_profile_id']), fn ($q) => $q->where('student_profile_id', $validated['student_profile_id']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['payment_status']), fn ($q) => $q->where('payment_status', $validated['payment_status']))
            ->when($request->boolean('overdue'), fn ($q) => $q
                ->where('status', MonthlyInvoiceStatus::Confirmed)
                ->where('payment_status', '!=', InvoicePaymentStatus::Paid)
                ->whereDate('due_on', '<', today()));
        $allForMonth = MonthlyInvoice::query()->whereDate('billing_month', $month)->get();

        return view('staff.invoices.index', [
            'month' => $month,
            'invoices' => $query->orderBy('student_profile_id')->paginate(50)->withQueryString(),
            'students' => StudentProfile::query()->with('user')->orderBy('id')->get()->sortBy('user.name'),
            'setting' => BillingSetting::current(),
            'canManage' => $request->user()->can('manage', MonthlyInvoice::class),
            'summary' => [
                'students' => $allForMonth->count(),
                'draft' => $allForMonth->where('status', MonthlyInvoiceStatus::Draft)->count(),
                'confirmed' => $allForMonth->where('status', MonthlyInvoiceStatus::Confirmed)->count(),
                'unpaid' => $allForMonth->where('payment_status', InvoicePaymentStatus::Unpaid)->count(),
                'partial' => $allForMonth->where('payment_status', InvoicePaymentStatus::PartiallyPaid)->count(),
                'paid' => $allForMonth->where('payment_status', InvoicePaymentStatus::Paid)->count(),
                'overdue' => $allForMonth->filter->is_overdue->count(),
                'total' => $allForMonth->where('status', '!=', MonthlyInvoiceStatus::Cancelled)->sum('total_amount'),
                'received' => $allForMonth->sum('paid_amount'),
                'outstanding' => $allForMonth->where('status', MonthlyInvoiceStatus::Confirmed)->sum(fn ($invoice) => $invoice->remaining_amount),
            ],
        ]);
    }

    public function show(MonthlyInvoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        return view('staff.invoices.show', [
            'invoice' => $invoice->load(['studentProfile.user', 'items.creator', 'paymentRecords.creator', 'audits.actor']),
            'canManage' => request()->user()->can('manage', MonthlyInvoice::class),
            'paymentMethods' => PaymentMethod::cases(),
            'paymentIdempotencyKey' => Str::uuid()->toString(),
        ]);
    }

    public function generate(Request $request, MonthlyInvoiceGenerator $generator): RedirectResponse
    {
        Gate::authorize('manage', MonthlyInvoice::class);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $generated = $generator->generate($data['month'], $request->user());

        return back()->with('success', $generated->count().'件の請求下書きを生成・確認しました。');
    }

    public function addAdjustment(Request $request, MonthlyInvoice $invoice, AddInvoiceAdjustment $action): RedirectResponse
    {
        Gate::authorize('manage', MonthlyInvoice::class);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $action->handle($invoice, $request->user(), (int) $data['amount'], $data['reason'], $data['description']);

        return back()->with('success', '調整明細を追加しました。');
    }

    public function confirm(Request $request, MonthlyInvoice $invoice, ConfirmMonthlyInvoice $action): RedirectResponse
    {
        Gate::authorize('manage', MonthlyInvoice::class);
        $action->handle($invoice, $request->user(), $request->boolean('acknowledge_warnings'));

        return back()->with('success', '請求を確定し、生徒へ公開しました。');
    }

    public function cancel(Request $request, MonthlyInvoice $invoice, CancelMonthlyInvoice $action): RedirectResponse
    {
        Gate::authorize('manage', MonthlyInvoice::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->handle($invoice, $request->user(), $data['reason']);

        return back()->with('success', '請求を履歴付きで取り消しました。');
    }

    public function reissue(Request $request, MonthlyInvoice $invoice, ReissueMonthlyInvoice $action): RedirectResponse
    {
        Gate::authorize('manage', MonthlyInvoice::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $draft = $action->handle($invoice, $request->user(), $data['reason']);

        return redirect()->route('staff.invoices.show', $draft)->with('success', '再発行先の請求を開きました。明細を確認してから確定してください。');
    }

    public function registerPayment(RegisterInvoicePaymentRequest $request, MonthlyInvoice $invoice, RegisterInvoicePayment $action): RedirectResponse
    {
        $action->handle($invoice, $request->user(), $request->validated());

        return back()->with('success', '入金を登録しました。');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        Gate::authorize('manage', MonthlyInvoice::class);
        $data = $request->validate([
            'generation_day' => ['required', 'integer', 'between:1,28'],
            'generation_time' => ['required', 'date_format:H:i'],
            'due_rule' => ['required', Rule::in(['previous_month_end', 'billing_month_day'])],
            'due_day' => ['nullable', 'integer', 'between:1,28', 'required_if:due_rule,billing_month_day'],
        ]);
        BillingSetting::current()->update([
            ...$data,
            'auto_generate_enabled' => $request->boolean('auto_generate_enabled'),
            'invoice_notifications_enabled' => $request->boolean('invoice_notifications_enabled'),
            'payment_notifications_enabled' => $request->boolean('payment_notifications_enabled'),
            'updated_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', '請求設定を更新しました。');
    }
}
