<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewReservationRequest as ReviewReservationAction;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewReservationRequest;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReservationReviewController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ReservationRequest::class);

        /** @var User $user */
        $user = $request->user();
        $teacherProfile = $user->teacherProfile;
        $reservations = ReservationRequest::query()
            ->with(['studentProfile.user', 'lessonSlot.teacherProfile', 'lessonSlot.venue', 'lessonSlot.course', 'reviewer'])
            ->when(
                $user->role === UserRole::Teacher,
                fn ($query) => $teacherProfile === null
                    ? $query->whereRaw('1 = 0')
                    : $query->whereHas('lessonSlot', fn ($slots) => $slots->whereBelongsTo($teacherProfile, 'teacherProfile'))
            )
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->paginate(30);

        return view('staff.reservations.index', compact('reservations'));
    }

    public function update(ReviewReservationRequest $request, ReservationRequest $reservationRequest, ReviewReservationAction $reviewReservation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reviewReservation->handle(
            $reservationRequest,
            $user,
            ReservationStatus::from($request->validated('decision')),
            $request->validated('staff_note'),
        );

        $message = $request->validated('decision') === ReservationStatus::Approved->value
            ? '予約申請を承認しました。'
            : '予約申請を却下しました。';

        return redirect()->route('staff.reservations.index')->with('success', $message);
    }
}
