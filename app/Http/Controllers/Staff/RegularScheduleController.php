<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ChangeRegularScheduleOccurrenceStatus;
use App\Actions\ConfirmRegularScheduleOccurrences;
use App\Actions\UpdateRegularScheduleOccurrence;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\RegularScheduleOccurrenceStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\LessonEnrollment;
use App\Models\RegularScheduleBatch;
use App\Models\RegularScheduleOccurrence;
use App\Models\RegularScheduleSetting;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use App\Services\RegularScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RegularScheduleController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', RegularScheduleOccurrence::class);
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->addMonth()->format('Y-m'), config('app.timezone'));
        /** @var User $user */
        $user = $request->user();

        $batches = RegularScheduleBatch::query()
            ->with(['lessonEnrollment.studentProfile.user', 'lessonEnrollment.teacherProfile', 'lessonEnrollment.venue', 'lessonEnrollment.course', 'occurrences.teacherProfile', 'occurrences.venue'])
            ->whereDate('entitlement_month', $month)
            ->when($user->role === UserRole::Teacher, fn (Builder $query) => $query->whereHas('lessonEnrollment', fn (Builder $enrollments) => $enrollments->where('teacher_profile_id', $user->teacherProfile?->id ?? 0)))
            ->orderBy('id')->get();

        $eligible = LessonEnrollment::query()
            ->where('lesson_type', LessonType::Regular)
            ->where('status', EnrollmentStatus::Active)
            ->whereDate('starts_on', '<=', $month->endOfMonth())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $month))
            ->when($user->role === UserRole::Teacher, fn (Builder $query) => $query->where('teacher_profile_id', $user->teacherProfile?->id ?? 0));

        return view('staff.regular-schedules.index', [
            'month' => $month,
            'batches' => $batches,
            'unGeneratedCount' => (clone $eligible)->whereDoesntHave('regularScheduleBatches', fn (Builder $query) => $query->whereDate('entitlement_month', $month))->count(),
            'settings' => RegularScheduleSetting::current(),
            'teachers' => TeacherProfile::query()->with('user')->orderBy('display_name')->get(),
            'venues' => Venue::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function generate(Request $request, RegularScheduleGenerator $generator): RedirectResponse
    {
        Gate::authorize('viewAny', RegularScheduleOccurrence::class);
        $validated = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'], config('app.timezone'));
        $batches = $generator->generateMonth($month, $request->user());

        return back()->with('success', $month->format('Y年n月').'分を生成・差分更新しました（'.$batches->count().'契約）。');
    }

    public function updateOccurrence(Request $request, RegularScheduleOccurrence $regularScheduleOccurrence, UpdateRegularScheduleOccurrence $action): RedirectResponse
    {
        Gate::authorize('update', $regularScheduleOccurrence);
        $validated = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'teacher_profile_id' => ['required', 'integer', Rule::exists('teacher_profiles', 'id')],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('is_active', true)],
        ]);
        /** @var User $user */
        $user = $request->user();
        if ($user->role === UserRole::Teacher) {
            $validated['teacher_profile_id'] = $user->teacherProfile->id;
        }
        $action->handle($regularScheduleOccurrence, $user, $validated);

        return back()->with('success', '下書きを更新し、競合を再確認しました。');
    }

    public function confirm(Request $request, ConfirmRegularScheduleOccurrences $action): RedirectResponse
    {
        Gate::authorize('viewAny', RegularScheduleOccurrence::class);
        $validated = $request->validate([
            'occurrence_ids' => ['required', 'array', 'min:1'],
            'occurrence_ids.*' => ['integer', Rule::exists('regular_schedule_occurrences', 'id')],
            'skip_conflicts' => ['nullable', 'boolean'],
        ]);
        $occurrences = RegularScheduleOccurrence::query()->whereKey($validated['occurrence_ids'])->get();
        foreach ($occurrences as $occurrence) {
            Gate::authorize('update', $occurrence);
        }
        $confirmed = $action->handle($occurrences->modelKeys(), $request->user(), $request->boolean('skip_conflicts'));

        return back()->with('success', $confirmed->count().'件の予定を確定しました。');
    }

    public function changeStatus(Request $request, RegularScheduleOccurrence $regularScheduleOccurrence, ChangeRegularScheduleOccurrenceStatus $action): RedirectResponse
    {
        Gate::authorize('update', $regularScheduleOccurrence);
        $validated = $request->validate([
            'status' => ['required', Rule::in([RegularScheduleOccurrenceStatus::Skipped->value, RegularScheduleOccurrenceStatus::Cancelled->value])],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('status') === RegularScheduleOccurrenceStatus::Cancelled->value)],
        ]);
        $action->handle($regularScheduleOccurrence, $request->user(), RegularScheduleOccurrenceStatus::from($validated['status']), $validated['reason'] ?? null);

        return back()->with('success', $validated['status'] === 'skipped' ? '予定をスキップしました。' : '確定予定を取り消し、予約履歴をキャンセル状態で保持しました。');
    }

    public function updatePattern(Request $request, LessonEnrollment $lessonEnrollment): RedirectResponse
    {
        Gate::authorize('viewAny', RegularScheduleOccurrence::class);
        /** @var User $user */
        $user = $request->user();
        abort_unless($lessonEnrollment->lesson_type === LessonType::Regular, 422);
        abort_if($user->role === UserRole::Teacher && $user->teacherProfile?->id !== $lessonEnrollment->teacher_profile_id, 403);
        $validated = $request->validate([
            'regular_week_numbers' => ['required', 'array', 'min:1', 'max:5'],
            'regular_week_numbers.*' => ['integer', 'between:1,5', 'distinct'],
        ]);
        if (count($validated['regular_week_numbers']) !== (int) $lessonEnrollment->monthly_lesson_limit) {
            return back()->withErrors(['regular_week_numbers' => '週の数は月回数と一致させてください。']);
        }
        $lessonEnrollment->update(['regular_week_numbers' => array_map('intval', $validated['regular_week_numbers'])]);

        return back()->with('success', '利用する週を更新しました。既存の手動編集・確定済み予定は変更されません。');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $validated = $request->validate([
            'automatic_generation_enabled' => ['nullable', 'boolean'],
            'generation_day' => ['required', 'integer', 'between:1,28'],
        ]);
        RegularScheduleSetting::current()->update([
            'automatic_generation_enabled' => $request->boolean('automatic_generation_enabled'),
            'generation_day' => $validated['generation_day'],
        ]);

        return back()->with('success', '自動生成設定を更新しました。');
    }
}
