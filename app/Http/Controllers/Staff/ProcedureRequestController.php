<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\ContractChangeRequest;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class ProcedureRequestController extends Controller
{
    public function __invoke(): View
    {
        Gate::authorize('viewAny', ContractChangeRequest::class);

        return view('staff.procedure-requests.index', [
            'contractRequests' => ContractChangeRequest::query()->with(['studentProfile.user', 'lessonEnrollment.course'])->latest('requested_at')->get(),
            'personalRequests' => PersonalInformationChangeRequest::query()->with('studentProfile.user')->latest('requested_at')->get(),
            'paymentRequests' => PaymentMethodChangeRequest::query()->with(['studentProfile.user', 'lessonEnrollment.course'])->latest('requested_at')->get(),
            'pendingCount' => ContractChangeRequest::query()->where('status', ApplicationStatus::Pending)->count()
                + PersonalInformationChangeRequest::query()->where('status', ApplicationStatus::Pending)->count()
                + PaymentMethodChangeRequest::query()->where('status', ApplicationStatus::Pending)->count(),
        ]);
    }
}
