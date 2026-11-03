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
use App\Services\TransferTermsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->paginate(20)->withQueryString();

        $lessons = ReservationRequest::query()
            ->select('reservation_requests.*')
            ->join('lesson_slots', 'lesson_slots.id', '=', 'reservation_requests.lesson_slot_id')
            ->where('reservation_requests.student_profile_id', $studentProfile?->id ?? 0)
            ->with(['lessonSlot.course', 'lessonSlot.venue', 'resultingTransferRequest'])
            ->withExists(['transferRequests as has_pending_transfer' => fn ($query) => $query->where('status', 'pending')])
            ->orderByRaw('CASE WHEN lesson_slots.starts_at >= ? THEN 0 ELSE 1 END', [now()])
            ->orderByRaw('CASE WHEN lesson_slots.starts_at >= ? THEN lesson_slots.starts_at END ASC', [now()])
            ->orderByDesc('lesson_slots.starts_at')
            ->orderBy('reservation_requests.id')
            ->paginate(20, ['reservation_requests.*'], 'lessons_page')->withQueryString();

        return view('student.transfer-requests.index', compact('transferRequests', 'lessons'));
    }

    public function create(ReservationRequest $reservationRequest, TeacherBookingAvailability $availability): View
    {
        Gate::authorize('requestTransfer', $reservationRequest);
        $reservationRequest->load(['lessonSlot.teacherProfile', 'lessonSlot.venue']);
        $availableSlots = app(TransferCalendarAvailability::class)->slots($reservationRequest);

        return view('student.transfer-requests.create', compact('reservationRequest', 'availableSlots'));
    }

    public function prepareConfirmation(
        StoreTransferRequestRequest $request,
        ReservationRequest $reservationRequest,
    ): RedirectResponse {
        $data = $request->validated();
        $request->session()->put($this->confirmationSessionKey($request->user()->id, $reservationRequest->id), $data);

        return redirect()->route('student.transfer-requests.confirmation', $reservationRequest);
    }

    public function showConfirmation(
        Request $request,
        ReservationRequest $reservationRequest,
        CreateTransferRequest $createTransferRequest,
        TransferTermsService $terms,
    ): View|RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->studentProfile?->is($reservationRequest->studentProfile) === true, 403);

        $sessionKey = $this->confirmationSessionKey($user->id, $reservationRequest->id);
        $data = $request->session()->get($sessionKey);
        if (! is_array($data)) {
            return redirect()->route('student.transfer-requests.create', $reservationRequest)
                ->withErrors(['reservation' => '振替内容が見つかりません。空き枠を選び直してください。']);
        }

        $reservationRequest->loadMissing(['studentProfile', 'lessonSlot.venue', 'lessonSlot.course', 'lessonEnrollment']);
        $target = null;
        $confirmationErrors = collect();
        try {
            if (! (bool) ($data['defer_schedule'] ?? false)) {
                if (filled($data['requested_starts_at'] ?? null)) {
                    $target = app(TransferCalendarAvailability::class)->slots($reservationRequest)
                        ->first(fn (LessonSlot $slot) => $slot->starts_at->format('Y-m-d H:i') === $data['requested_starts_at']);
                    if ($target === null) {
                        throw ValidationException::withMessages(['requested_starts_at' => '選択した空き枠は現在利用できません。カレンダーから別の日時を選んでください。']);
                    }
                } else {
                    $target = LessonSlot::query()->with('venue')->find($data['requested_lesson_slot_id'] ?? null);
                    if ($target === null) {
                        throw ValidationException::withMessages(['requested_lesson_slot_id' => '選択したレッスン枠は利用できません。空き枠を選び直してください。']);
                    }
                }

                LessonOperatingHours::assertAllowed($target->starts_at, $target->ends_at, 'requested_starts_at');
                if ($target->starts_at->gt(today()->addMonthNoOverflow()->endOfDay())) {
                    throw ValidationException::withMessages(['requested_starts_at' => '振替先は1か月先まで選択できます。']);
                }
            }

            $createTransferRequest->assertCanRequest(
                $reservationRequest->studentProfile,
                $reservationRequest,
                $target,
                $data['reason'] ?? null,
                (bool) ($data['special_handling'] ?? false),
            );
        } catch (ValidationException $exception) {
            $confirmationErrors = collect($exception->errors())->flatten()->values();
        }

        $previewRequest = new TransferRequest([
            'student_profile_id' => $reservationRequest->student_profile_id,
            'original_reservation_request_id' => $reservationRequest->id,
            'requested_lesson_slot_id' => $target?->exists ? $target->id : null,
        ]);
        $previewRequest->setRelation('studentProfile', $reservationRequest->studentProfile);
        $previewRequest->setRelation('originalReservationRequest', $reservationRequest);
        $quote = $terms->quote(
            $previewRequest,
            CarbonImmutable::now(config('app.timezone')),
            ! empty($data['special_handling']) ? 'emergency' : 'student',
        );
        $canConfirm = $confirmationErrors->isEmpty();

        return view('student.transfer-requests.confirm', compact(
            'reservationRequest', 'target', 'data', 'quote', 'canConfirm', 'confirmationErrors',
        ));
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

        if ($user->role === UserRole::Student) {
            $request->session()->forget($this->confirmationSessionKey($user->id, $reservationRequest->id));

            return redirect()->route('student.reservations.index')->with('success', '振替申請を受け付けました。レッスン一覧に「振替申請中」と表示しています。先生の確認後に正式確定します。');
        }

        return redirect()->route('staff.transfer-requests.index')->with('success', '振替希望を受け付けました。講師の確定により正式となります。');
    }

    private function confirmationSessionKey(int $userId, int $reservationId): string
    {
        return "student.transfer-confirmation.{$userId}.{$reservationId}";
    }
}
