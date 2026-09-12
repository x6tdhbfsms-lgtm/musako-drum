<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreatePersonalInformationChangeRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonalInformationChangeRequestRequest;
use App\Models\PersonalInformationChangeRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PersonalInformationChangeRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', PersonalInformationChangeRequest::class);
        $profile = $request->user()->studentProfile->load('user');
        $requests = $profile->personalInformationChangeRequests()->with('reviewer')->latest('requested_at')->get();

        return view('student.personal-information-change-requests.index', compact('profile', 'requests'));
    }

    public function store(StorePersonalInformationChangeRequestRequest $request, CreatePersonalInformationChangeRequest $create): RedirectResponse
    {
        $create->handle($request->user()->studentProfile, $request->validated());

        return redirect()->route('student.personal-information-change-requests.index')->with('success', '個人情報の変更を申請しました。');
    }
}
