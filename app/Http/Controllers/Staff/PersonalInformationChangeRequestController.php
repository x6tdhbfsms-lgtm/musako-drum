<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewPersonalInformationChangeRequest;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewApplicationRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PersonalInformationChangeRequestController extends Controller
{
    public function show(PersonalInformationChangeRequest $personalChange): View
    {
        Gate::authorize('view', $personalChange);
        $personalChange->load(['studentProfile.user', 'reviewer']);
        $personalInformationChangeRequest = $personalChange;

        return view('staff.procedure-requests.personal-show', compact('personalInformationChangeRequest'));
    }

    public function update(ReviewApplicationRequest $request, PersonalInformationChangeRequest $personalChange, ReviewPersonalInformationChangeRequest $review): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $review->handle($personalChange, $user, ApplicationStatus::from($request->validated('decision')), $request->validated('rejection_reason'));

        return redirect()->route('staff.personal-information-change-requests.show', $personalChange)->with('success', '個人情報変更申請を処理しました。');
    }
}
