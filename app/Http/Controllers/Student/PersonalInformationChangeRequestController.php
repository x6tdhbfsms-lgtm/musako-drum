<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreatePersonalInformationChangeRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeStudentPasswordRequest;
use App\Http\Requests\StorePersonalInformationChangeRequestRequest;
use App\Models\PersonalInformationChangeRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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

    public function changePassword(ChangeStudentPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $request->session()->regenerate();

        return redirect()->route('student.personal-information-change-requests.index')
            ->with('success', 'パスワードを変更しました。他の端末では再ログインが必要です。');
    }
}
