<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreateReservationRequest;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\User;
use App\Services\MusakoNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReservationRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ReservationRequest::class);

        /** @var User $user */
        $user = $request->user();
        $reservations = ReservationRequest::query()
            ->when(
                $user->studentProfile === null,
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->whereBelongsTo($user->studentProfile, 'studentProfile')
            )
            ->with(['lessonSlot.teacherProfile', 'lessonSlot.venue', 'lessonSlot.course'])
            ->orderByDesc(
                LessonSlot::query()->select('starts_at')->whereColumn('lesson_slots.id', 'reservation_requests.lesson_slot_id')
            )
            ->paginate(20);

        return view('student.reservations.index', compact('reservations'));
    }

    public function store(StoreReservationRequest $request, LessonSlot $lessonSlot, CreateReservationRequest $createReservation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $createReservation->handle($user->studentProfile, $lessonSlot, $request->validated('student_note'));

        return redirect()->route('student.reservations.index')->with('success', '予約を申請しました。先生の承認をお待ちください。');
    }

    public function destroy(
        CancelReservationRequest $request,
        ReservationRequest $reservationRequest,
        MusakoNotificationService $notifications,
    ): RedirectResponse {
        $reservationRequest->update([
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $request->validated('cancellation_reason'),
        ]);
        $notifications->reservationCancelled($reservationRequest);

        return redirect()->route('student.reservations.index')->with('success', '予約をキャンセルしました。');
    }
}
