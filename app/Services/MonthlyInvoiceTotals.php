<?php

namespace App\Services;

use App\Enums\InvoiceItemType;
use App\Enums\InvoicePaymentStatus;
use App\Models\MonthlyInvoice;

class MonthlyInvoiceTotals
{
    public function recalculate(MonthlyInvoice $invoice): MonthlyInvoice
    {
        $invoice->load('items');
        $subtotal = (int) $invoice->items->where('is_manual', false)->sum('amount');
        $adjustments = (int) $invoice->items->where('is_manual', true)->sum('amount');
        $total = $subtotal + $adjustments;
        $paid = (int) $invoice->paymentRecords()->sum('amount');
        $paymentStatus = match (true) {
            $paid === 0 => InvoicePaymentStatus::Unpaid,
            $paid < $total => InvoicePaymentStatus::PartiallyPaid,
            default => InvoicePaymentStatus::Paid,
        };

        $invoice->update([
            'base_lesson_fee' => $invoice->items->where('type', InvoiceItemType::LessonFee)->sum('amount') ?: null,
            'flex_surcharge' => (int) $invoice->items->where('type', InvoiceItemType::FlexSurcharge)->sum('amount'),
            'lesson_fee_total' => $invoice->items->whereIn('type', [InvoiceItemType::LessonFee, InvoiceItemType::FlexSurcharge])->sum('amount') ?: null,
            'studio_fee_total' => (int) $invoice->items->where('type', InvoiceItemType::StudioFee)->sum('amount'),
            'subtotal' => $subtotal,
            'adjustments_total' => $adjustments,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'payment_status' => $paymentStatus,
        ]);

        return $invoice->refresh();
    }
}
