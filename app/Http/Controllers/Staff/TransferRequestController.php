<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewTransferRequest;
use App\Actions\ScheduleDeferredTransfer;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewTransferRequestRequest;
use App\Http\Requests\ScheduleDeferredTransferRequest;
use App\Models\LessonSlot;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\TransferTermsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class TransferRequestController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', TransferRequest::class);
        /** @var User $user */
        $user = request()->user();
        $teacherProfile = $user->teacherProfile;
        $transferRequests = TransferRequest::query()
            ->with([
                'studentProfile.user',
                'originalReservationRequest.lessonSlot.teacherProfile',
                'requestedLessonSlot.teacherProfile',
                'requestedLessonSlot.venue',
            ])
            ->when(
                $user->role === UserRole::Teacher,
                fn ($query) => $teacherProfile === null
                    ? $query->whereRaw('1 = 0')
                    : $query->where(function ($requests) use ($teacherProfile): void {
                        $requests->whereHas('originalReservationRequest.lessonSlot', fn ($slots) => $slots->whereBelongsTo($teacherProfile, 'teacherProfile'))
                            ->orWhereHas('requestedLessonSlot', fn ($slots) => $slots->whereBelongsTo($teacherProfile, 'teacherProfile'));
                    })
            )
            ->when(request()->routeIs('staff.transfer-requests.deferred'), fn ($query) => $query->whereNull('requested_lesson_slot_id')->whereIn('status', [ApplicationStatus::Pending, ApplicationStatus::Approved]))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('requested_at')
            ->paginate(30);

        return view('staff.transfer-requests.index', compact('transferRequests'));
    }

    public function show(TransferRequest $transferRequest, TransferTermsService $terms): View
    {
        Gate::authorize('view', $transferRequest);
        $transferRequest->load([
            'studentProfile.user',
            'originalReservationRequest.lessonSlot.teacherProfile',
            'originalReservationRequest.lessonSlot.venue',
            'requestedLessonSlot.teacherProfile',
            'requestedLessonSlot.venue',
            'resultingReservationRequest',
            'reviewer',
        ]);

        $annualAllowance = $terms->annualAllowance($transferRequest->studentProfile, CarbonImmutable::instance($transferRequest->originalReservationRequest->lessonSlot->starts_at));

        $termsQuote = $transferRequest->terms_snapshot ?? $terms->quote(
            $transferRequest,
            CarbonImmutable::instance($transferRequest->requested_at)->setTimezone(config('app.timezone')),
        );
        $termsUseSubmissionTime = $transferRequest->status === ApplicationStatus::Pending
            || ($transferRequest->terms_snapshot['basis'] ?? null) === 'request_submission';
        $requestedOptions = LessonSlot::query()->with('venue')
            ->whereIn('id', $transferRequest->requested_lesson_slot_ids ?: array_filter([$transferRequest->requested_lesson_slot_id]))
            ->get()->sortBy(fn (LessonSlot $slot): int => array_search($slot->id, $transferRequest->requested_lesson_slot_ids ?: [$transferRequest->requested_lesson_slot_id], true) ?: 0)->values();

        return view('staff.transfer-requests.show', compact('transferRequest', 'annualAllowance', 'termsQuote', 'termsUseSubmissionTime', 'requestedOptions'));
    }

    public function schedule(ScheduleDeferredTransferRequest $request, TransferRequest $transferRequest, ScheduleDeferredTransfer $schedule): RedirectResponse
    {
        $schedule->handle($transferRequest, $request->user(), CarbonImmutable::parse($request->validated('starts_at'), config('app.timezone')));

        return redirect()->route('staff.transfer-requests.show', $transferRequest)->with('success', '保留していたレッスンをカレンダーに追加しました。');
    }

    public function update(
        ReviewTransferRequestRequest $request,
        TransferRequest $transferRequest,
        ReviewTransferRequest $reviewTransferRequest,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $reviewTransferRequest->handle(
            $transferRequest,
            $user,
            ApplicationStatus::from($request->validated('decision')),
            $request->validated('staff_note'),
            $request->validated('cause', 'student'),
            $request->validated('selected_lesson_slot_id') ? (int) $request->validated('selected_lesson_slot_id') : null,
        );

        return redirect()->route('staff.transfer-requests.show', $transferRequest)->with('success', '振替申請を処理しました。');
    }
}
