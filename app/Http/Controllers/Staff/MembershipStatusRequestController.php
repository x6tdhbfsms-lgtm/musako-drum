<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewMembershipStatusRequest;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewMembershipStatusRequestRequest;
use App\Models\MembershipStatusRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class MembershipStatusRequestController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', MembershipStatusRequest::class);
        $membershipStatusRequests = MembershipStatusRequest::query()
            ->with(['studentProfile.user', 'reviewer'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('requested_at')
            ->paginate(30);

        return view('staff.membership-status-requests.index', compact('membershipStatusRequests'));
    }

    public function show(MembershipStatusRequest $membershipStatusRequest): View
    {
        Gate::authorize('view', $membershipStatusRequest);
        $membershipStatusRequest->load(['studentProfile.user', 'studentProfile.enrollments.course', 'reviewer']);

        return view('staff.membership-status-requests.show', compact('membershipStatusRequest'));
    }

    public function update(
        ReviewMembershipStatusRequestRequest $request,
        MembershipStatusRequest $membershipStatusRequest,
        ReviewMembershipStatusRequest $reviewMembershipStatusRequest,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $reviewMembershipStatusRequest->handle(
            $membershipStatusRequest,
            $user,
            ApplicationStatus::from($request->validated('decision')),
            $request->validated('staff_note'),
        );

        return redirect()->route('staff.membership-status-requests.show', $membershipStatusRequest)->with('success', '在籍申請を処理しました。');
    }
}
