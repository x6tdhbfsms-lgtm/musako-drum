<?php

namespace App\Http\Controllers\Student;

use App\Actions\CreateTransferRequest;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransferRequestRequest;
use App\Models\LessonSlot;
use App\Models\ReservationRequest;
use App\Models\TeacherProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\LessonOperatingHours;
use App\Services\TeacherBookingAvailability;
use App\Services\TransferCalendarAvailability;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

    public function create(ReservationRequest $reservationRequest, TeacherBookingAvailability $availability): View
    {
        Gate::authorize('requestTransfer', $reservationRequest);
        $reservationRequest->load(['lessonSlot.teacherProfile', 'lessonSlot.venue']);
        $availableSlots = app(TransferCalendarAvailability::class)->slots($reservationRequest);

        return view('student.transfer-requests.create', compact('reservationRequest', 'availableSlots'));
    }

    public function store(
        StoreTransferRequestRequest $request,
        ReservationRequest $reservationRequest,
        CreateTransferRequest $createTransferRequest,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        DB::transaction(function () use ($request, $reservationRequest, $createTransferRequest): void {
            if ($request->boolean('defer_schedule')) {
                $createTransferRequest->handle(
                    $reservationRequest->studentProfile, $reservationRequest, null,
                    $request->validated('reason'), $request->validated('student_note'),
                    $request->boolean('special_handling'),
                );

                return;
            }
            $slotId = $request->integer('requested_lesson_slot_id');
            if ($request->filled('requested_starts_at')) {
                TeacherProfile::whereKey($reservationRequest->lessonSlot->teacher_profile_id)->lockForUpdate()->firstOrFail();
                $candidate = app(TransferCalendarAvailability::class)->slots($reservationRequest)
                    ->first(fn ($slot) => $slot->starts_at->format('Y-m-d H:i') === $request->input('requested_starts_at'));
                if ($candidate === null) {
                    throw ValidationException::withMessages(['requested_starts_at' => 'この日時は予約できなくなりました。空き時間を選び直してください。']);
                }
                $slot = LessonSlot::create($candidate->only(['teacher_profile_id', 'venue_id', 'course_id', 'starts_at', 'ends_at', 'capacity', 'status', 'booking_audience']));
                $slotId = $slot->id;
            }
            $target = LessonSlot::findOrFail($slotId);
            LessonOperatingHours::assertAllowed($target->starts_at, $target->ends_at, 'requested_starts_at');
            if ($target->starts_at->gt(today()->addMonthNoOverflow()->endOfDay())) {
                throw ValidationException::withMessages(['requested_starts_at' => '振替先は1か月先まで選択できます。']);
            }
            $createTransferRequest->handle(
                $reservationRequest->studentProfile,
                $reservationRequest,
                $slotId,
                $request->validated('reason'),
                $request->validated('student_note'),
                $request->boolean('special_handling'),
            );
        }, 3);

        return redirect()->route($user->role === UserRole::Student ? 'student.transfer-requests.index' : 'staff.transfer-requests.index')->with('success', '振替希望を受け付けました。講師の確定により正式となります。');
    }
}
