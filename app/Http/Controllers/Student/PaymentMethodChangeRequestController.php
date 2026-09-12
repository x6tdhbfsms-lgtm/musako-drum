<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreatePaymentMethodChangeRequest;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentMethodChangeRequestRequest;
use App\Models\PaymentMethodChangeRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentMethodChangeRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', PaymentMethodChangeRequest::class);
        $profile = $request->user()->studentProfile;

        return view('student.payment-method-change-requests.index', [
            'enrollments' => $profile->enrollments()->with('course')->activeOn(now())->get(),
            'requests' => $profile->paymentMethodChangeRequests()->with(['lessonEnrollment.course', 'reviewer'])->latest('requested_at')->get(),
            'paymentMethods' => PaymentMethod::cases(),
        ]);
    }

    public function store(StorePaymentMethodChangeRequestRequest $request, CreatePaymentMethodChangeRequest $create): RedirectResponse
    {
        $create->handle($request->user()->studentProfile, $request->validated());

        return redirect()->route('student.payment-method-change-requests.index')->with('success', '支払い方法の変更を申請しました。');
    }
}
