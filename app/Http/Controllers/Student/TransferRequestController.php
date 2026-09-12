<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreateTransferRequest;
use App\Enums\LessonSlotStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransferRequestRequest;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\TransferRequest;
use App\Models\User;
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
        $studentProfile = $user->studentProfile;
        $transferRequests = TransferRequest::query()
            ->with([
                'originalReservationRequest.lessonSlot',
                'requestedLessonSlot.teacherProfile',
                'requestedLessonSlot.venue',
                'reviewer',
            ])
            ->when(
                $studentProfile === null,
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->whereBelongsTo($studentProfile, 'studentProfile')
            )
            ->latest('requested_at')
            ->paginate(20);

        return view('student.transfer-requests.index', compact('transferRequests'));
    }

    public function create(ReservationRequest $reservationRequest): View
    {
        Gate::authorize('requestTransfer', $reservationRequest);
        $reservationRequest->load(['lessonSlot.teacherProfile', 'lessonSlot.venue']);
        $studentProfile = $reservationRequest->studentProfile;
        $existingSlotIds = $studentProfile->reservationRequests()->pluck('lesson_slot_id');
        $availableSlots = LessonSlot::query()
            ->with(['teacherProfile', 'venue', 'course'])
            ->withCount(['reservationRequests as approved_reservations_count' => fn ($query) => $query->where('status', ReservationStatus::Approved)])
            ->where('status', LessonSlotStatus::Open)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMonths(3))
            ->whereKeyNot($reservationRequest->lesson_slot_id)
            ->whereNotIn('id', $existingSlotIds)
            ->orderBy('starts_at')
            ->limit(100)
            ->get()
            ->filter(fn (LessonSlot $slot) => $slot->approved_reservations_count < $slot->capacity);

        return view('student.transfer-requests.create', compact('reservationRequest', 'availableSlots'));
    }

    public function store(
        StoreTransferRequestRequest $request,
        ReservationRequest $reservationRequest,
        CreateTransferRequest $createTransferRequest,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $createTransferRequest->handle(
            $user->studentProfile,
            $reservationRequest,
            $request->integer('requested_lesson_slot_id'),
            $request->validated('reason'),
            $request->validated('student_note'),
        );

        return redirect()->route('student.transfer-requests.index')->with('success', '振替を申請しました。先生の確認をお待ちください。');
    }
}
