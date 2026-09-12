<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreateMembershipStatusRequest;
use App\Enums\MembershipRequestType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembershipStatusRequestRequest;
use App\Models\MembershipStatusRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MembershipStatusRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MembershipStatusRequest::class);
        /** @var User $user */
        $user = $request->user();
        $studentProfile = $user->studentProfile;
        $membershipStatusRequests = MembershipStatusRequest::query()
            ->with('reviewer')
            ->when(
                $studentProfile === null,
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->whereBelongsTo($studentProfile, 'studentProfile')
            )
            ->latest('requested_at')
            ->paginate(20);
        $enrollments = $studentProfile?->enrollments()->with('course')->orderBy('starts_on')->get() ?? collect();
        $selectedType = MembershipRequestType::tryFrom($request->string('type')->toString()) ?? MembershipRequestType::Pause;

        return view('student.membership-status-requests.index', compact(
            'membershipStatusRequests',
            'enrollments',
            'selectedType',
        ));
    }

    public function store(
        StoreMembershipStatusRequestRequest $request,
        CreateMembershipStatusRequest $createMembershipStatusRequest,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $createMembershipStatusRequest->handle(
            $user->studentProfile,
            MembershipRequestType::from($request->validated('type')),
            CarbonImmutable::parse($request->validated('effective_on'), config('app.timezone')),
            $request->validated('reason'),
            $request->validated('student_note'),
        );

        return redirect()->route('student.membership-status-requests.index')->with('success', '在籍に関する申請を受け付けました。');
    }
}
