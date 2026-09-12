<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewPaymentMethodChangeRequest;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewApplicationRequest;
use App\Models\PaymentMethodChangeRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PaymentMethodChangeRequestController extends Controller
{
    public function show(PaymentMethodChangeRequest $paymentChange): View
    {
        Gate::authorize('view', $paymentChange);
        $paymentChange->load(['studentProfile.user', 'lessonEnrollment.course', 'reviewer']);
        $paymentMethodChangeRequest = $paymentChange;

        return view('staff.procedure-requests.payment-show', compact('paymentMethodChangeRequest'));
    }

    public function update(ReviewApplicationRequest $request, PaymentMethodChangeRequest $paymentChange, ReviewPaymentMethodChangeRequest $review): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $review->handle($paymentChange, $user, ApplicationStatus::from($request->validated('decision')), $request->validated('rejection_reason'));

        return redirect()->route('staff.payment-method-change-requests.show', $paymentChange)->with('success', '支払い方法変更申請を処理しました。');
    }
}
