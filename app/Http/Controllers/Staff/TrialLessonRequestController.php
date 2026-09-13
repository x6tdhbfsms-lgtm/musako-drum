<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewTrialLessonRequestAction;
use App\Enums\TrialLessonStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewTrialLessonRequest;
use App\Models\TrialLessonRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TrialLessonRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', TrialLessonRequest::class);
        /** @var User $user */
        $user = $request->user();
        $status = $request->string('status')->toString();
        $requests = TrialLessonRequest::query()
            ->with(['lessonSlot.teacherProfile', 'lessonSlot.venue', 'admissionApplication'])
            ->when($user->role === UserRole::Teacher, fn ($query) => $query->whereHas('lessonSlot', fn ($slots) => $slots->where('teacher_profile_id', $user->teacherProfile?->id ?? 0)))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('requested_at')
            ->paginate(25)
            ->withQueryString();

        return view('staff.trial-lessons.index', compact('requests', 'status'));
    }

    public function show(TrialLessonRequest $trialLessonRequest): View
    {
        Gate::authorize('view', $trialLessonRequest);
        $trialLessonRequest->load(['lessonSlot.teacherProfile', 'lessonSlot.venue', 'lessonSlot.course', 'events.actor', 'admissionApplication']);

        return view('staff.trial-lessons.show', compact('trialLessonRequest'));
    }

    public function update(ReviewTrialLessonRequest $request, TrialLessonRequest $trialLessonRequest, ReviewTrialLessonRequestAction $review): RedirectResponse
    {
        $review->handle(
            $trialLessonRequest,
            $request->user(),
            TrialLessonStatus::from($request->validated('status')),
            $request->validated('rejection_reason'),
        );

        return back()->with('success', '体験レッスン申込みを更新しました。');
    }
}
