<?php

namespace App\Http\Controllers\Staff;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLessonSlotRequest;
use App\Http\Requests\UpdateLessonSlotRequest;
use App\Models\Course;
use App\Models\LessonSlot;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use App\Services\LessonSlotCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LessonSlotController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', LessonSlot::class);

        /** @var User $user */
        $user = $request->user();
        $teacherProfile = $user->teacherProfile;
        $lessonSlots = LessonSlot::query()
            ->with(['teacherProfile.user', 'venue', 'course'])
            ->withCount('reservationRequests')
            ->when(
                $user->role === UserRole::Teacher,
                fn ($query) => $teacherProfile === null ? $query->whereRaw('1 = 0') : $query->whereBelongsTo($teacherProfile, 'teacherProfile')
            )
            ->orderBy('starts_at')
            ->paginate(20);

        return view('staff.lesson-slots.index', compact('lessonSlots'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', LessonSlot::class);
        $validated = $request->validate(['starts_at' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        $suggestedStartsAt = $validated['starts_at'] ?? null;
        $suggestedEndsAt = $suggestedStartsAt === null
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $suggestedStartsAt, config('app.timezone'))->addHour()->format('Y-m-d\TH:i');

        return view('staff.lesson-slots.create', [
            ...$this->formOptions(),
            'suggestedStartsAt' => $suggestedStartsAt,
            'suggestedEndsAt' => $suggestedEndsAt,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLessonSlotRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->safe()->except('teacher_profile_id');
        $attributes['teacher_profile_id'] = $user->role === UserRole::Admin
            ? $request->integer('teacher_profile_id')
            : $user->teacherProfile->id;

        LessonSlot::create($attributes);

        return redirect()->route('staff.lesson-slots.index')->with('success', 'レッスン枠を作成しました。');
    }

    /**
     * Display the specified resource.
     */
    public function edit(LessonSlot $lessonSlot): View
    {
        Gate::authorize('update', $lessonSlot);

        return view('staff.lesson-slots.edit', [...$this->formOptions(), 'lessonSlot' => $lessonSlot]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLessonSlotRequest $request, LessonSlot $lessonSlot, LessonSlotCapacityService $capacity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->safe()->except('teacher_profile_id');
        $attributes['teacher_profile_id'] = $user->role === UserRole::Admin
            ? $request->integer('teacher_profile_id')
            : $user->teacherProfile->id;
        DB::transaction(function () use ($lessonSlot, $attributes, $capacity): void {
            $lockedSlot = LessonSlot::query()->lockForUpdate()->findOrFail($lessonSlot->id);
            $reserved = $capacity->approvedReservationCount($lockedSlot) + $capacity->activeTrialCount($lockedSlot);
            if ((int) $attributes['capacity'] < $reserved) {
                throw ValidationException::withMessages([
                    'capacity' => '予約状況が更新されています。現在の予約数より定員を少なくできません。画面を再読み込みしてください。',
                ]);
            }
            $lockedSlot->update($attributes);
        });

        return redirect()->route('staff.lesson-slots.index')->with('success', 'レッスン枠を更新しました。');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LessonSlot $lessonSlot): RedirectResponse
    {
        Gate::authorize('delete', $lessonSlot);

        if ($lessonSlot->reservationRequests()->exists() || $lessonSlot->trialLessonRequests()->exists()) {
            return back()->with('error', '予約申請がある枠は削除できません。枠を中止へ変更してください。');
        }

        $lessonSlot->delete();

        return redirect()->route('staff.lesson-slots.index')->with('success', 'レッスン枠を削除しました。');
    }

    /** @return array{teachers: Collection<int, TeacherProfile>, venues: Collection<int, Venue>, courses: Collection<int, Course>} */
    private function formOptions(): array
    {
        return [
            'teachers' => TeacherProfile::query()->with('user')->orderBy('display_name')->get(),
            'venues' => Venue::query()->where('is_active', true)->orderBy('name')->get(),
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
