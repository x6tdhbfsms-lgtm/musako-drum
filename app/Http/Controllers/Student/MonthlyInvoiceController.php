<?php

namespace App\Http\Controllers\Student;

use App\Enums\MonthlyInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\MonthlyInvoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MonthlyInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MonthlyInvoice::class);
        $invoices = MonthlyInvoice::query()
            ->whereIn('status', [MonthlyInvoiceStatus::Confirmed, MonthlyInvoiceStatus::Cancelled])
            ->whereBelongsTo($request->user()->studentProfile, 'studentProfile')
            ->latest('billing_month')
            ->paginate(24);

        return view('student.invoices.index', compact('invoices'));
    }

    public function show(MonthlyInvoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        return view('student.invoices.show', ['invoice' => $invoice->load(['items', 'paymentRecords'])]);
    }
}
