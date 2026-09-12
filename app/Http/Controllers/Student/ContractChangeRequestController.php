<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreateContractChangeRequest;
use App\Enums\ContractChangeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractChangeRequestRequest;
use App\Models\ContractChangeRequest;
use App\Models\Course;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContractChangeRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ContractChangeRequest::class);
        $profile = $request->user()->studentProfile;
        $selectedType = ContractChangeType::tryFrom($request->string('type')->toString()) ?? ContractChangeType::Schedule;
        $enrollments = $profile->enrollments()
            ->with(['course', 'teacherProfile', 'venue'])
            ->activeOn(now())
            ->orderBy('starts_on')
            ->get();
        $requests = $profile->contractChangeRequests()
            ->with(['lessonEnrollment.course', 'reviewer'])
            ->latest('requested_at')
            ->get();

        return view('student.contract-change-requests.index', [
            'selectedType' => $selectedType,
            'enrollments' => $enrollments,
            'requests' => $requests,
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(),
            'venues' => Venue::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreContractChangeRequestRequest $request, CreateContractChangeRequest $create): RedirectResponse
    {
        $changeRequest = $create->handle($request->user()->studentProfile, $request->validated());

        return redirect()->route('student.contract-change-requests.index', ['type' => $changeRequest->type->value])
            ->with('success', '契約内容の変更を申請しました。');
    }
}
