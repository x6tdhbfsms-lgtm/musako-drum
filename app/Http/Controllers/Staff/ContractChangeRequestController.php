<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewContractChangeRequest;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewApplicationRequest;
use App\Models\ContractChangeRequest;
use App\Models\Course;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ContractChangeRequestController extends Controller
{
    public function show(ContractChangeRequest $contractChangeRequest): View
    {
        Gate::authorize('view', $contractChangeRequest);
        $contractChangeRequest->load(['studentProfile.user', 'lessonEnrollment.course', 'reviewer']);

        $requestedCourse = isset($contractChangeRequest->after_values['course_id'])
            ? Course::find($contractChangeRequest->after_values['course_id'])
            : null;
        $requestedVenue = isset($contractChangeRequest->after_values['venue_id'])
            ? Venue::find($contractChangeRequest->after_values['venue_id'])
            : null;

        return view('staff.procedure-requests.contract-show', compact('contractChangeRequest', 'requestedCourse', 'requestedVenue'));
    }

    public function update(ReviewApplicationRequest $request, ContractChangeRequest $contractChangeRequest, ReviewContractChangeRequest $review): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $review->handle($contractChangeRequest, $user, ApplicationStatus::from($request->validated('decision')), $request->validated('rejection_reason'));

        return redirect()->route('staff.contract-change-requests.show', $contractChangeRequest)->with('success', '契約変更申請を処理しました。');
    }
}
