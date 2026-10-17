<?php

namespace App\Http\Controllers\Staff;

use App\Actions\UpdateStaffEnrollment;
use App\Actions\UpdateStaffStudent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStaffEnrollmentRequest;
use App\Http\Requests\UpdateStaffStudentRequest;
use App\Models\Course;
use App\Models\LessonEnrollment;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\Venue;
use App\Services\FlexInactivityWarning;
use App\Services\LessonPricingService;
use App\Support\MonthlyLessonUsageCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentController extends Controller
{
    public function edit(StudentProfile $student, UpdateStaffStudent $update): View
    {
        Gate::authorize('update', $student);
        $student->load(['user', 'enrollments.course', 'enrollments.replacements']);

        return view('staff.students.edit', ['student' => $student, 'revision' => $update->revision($student)]);
    }

    public function update(UpdateStaffStudentRequest $request, StudentProfile $student, UpdateStaffStudent $update): RedirectResponse
    {
        $update->handle($student, $request->user(), $request->validated());

        return redirect()->route('staff.students.show', $student)->with('success', '生徒の基本情報を更新しました。');
    }

    public function editEnrollment(StudentProfile $student, LessonEnrollment $enrollment): View
    {
        Gate::authorize('update', $student);
        abort_unless($enrollment->student_profile_id === $student->id, 404);

        return view('staff.students.edit-enrollment', [
            'student' => $student->load('user'), 'enrollment' => $enrollment,
            'courses' => Course::where('is_active', true)->orderBy('name')->get(),
            'venues' => Venue::where('is_active', true)->orderBy('name')->get(),
            'teachers' => TeacherProfile::orderBy('display_name')->get(),
        ]);
    }

    public function updateEnrollment(UpdateStaffEnrollmentRequest $request, StudentProfile $student, LessonEnrollment $enrollment, UpdateStaffEnrollment $update): RedirectResponse
    {
        $update->handle($student, $enrollment, $request->user(), $request->validated());

        return redirect()->route('staff.students.show', $student)->with('success', '適用日を指定して契約情報を更新しました。作成済みのレッスン予定と請求は変更していません。必要に応じて予定を確認してください。');
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', StudentProfile::class);
        /** @var User $user */
        $user = $request->user();
        $students = StudentProfile::query()
            ->with(['user', 'enrollments' => fn ($query) => $query->activeOn(now())->with(['course', 'teacherProfile', 'venue'])])
            ->when($user->role === UserRole::Teacher, fn ($query) => $query->where(function ($students) use ($user): void {
                $students->whereHas('enrollments', fn ($enrollments) => $enrollments->whereBelongsTo($user->teacherProfile, 'teacherProfile'))
                    ->orWhereHas('reservationRequests.lessonSlot', fn ($slots) => $slots->whereBelongsTo($user->teacherProfile, 'teacherProfile'));
            }))
            ->whereHas('user')
            ->orderBy('id')
            ->paginate(30);

        return view('staff.students.index', compact('students'));
    }

    public function show(
        Request $request,
        StudentProfile $student,
        MonthlyLessonUsageCalculator $monthlyLessonUsage,
        LessonPricingService $pricingService,
        FlexInactivityWarning $flexInactivityWarning,
    ): View {
        Gate::authorize('view', $student);
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'), config('app.timezone'))->startOfMonth();
        $studentProfile = $student;
        $studentProfile->load([
            'user',
            'enrollments' => fn ($query) => $query->with(['course', 'teacherProfile', 'venue'])->orderByDesc('starts_on'),
            'transferRequests.originalReservationRequest.lessonSlot',
            'transferRequests.requestedLessonSlot',
            'membershipStatusRequests',
            'contractChangeRequests',
            'personalInformationChangeRequests',
            'paymentMethodChangeRequests',
            'inquiries',
        ]);
        $upcomingReservations = $studentProfile->reservationRequests()->with(['lessonSlot.course', 'lessonSlot.teacherProfile', 'lessonSlot.venue'])
            ->whereHas('lessonSlot', fn ($query) => $query->where('starts_at', '>=', now()))
            ->latest('requested_at')->get();
        $recentReservations = $studentProfile->reservationRequests()->with(['lessonSlot.course', 'attendanceNotice'])
            ->whereHas('lessonSlot', fn ($query) => $query->where('starts_at', '<', now()))
            ->latest('requested_at')->limit(20)->get();

        $monthlySummary = $monthlyLessonUsage->calculate($studentProfile, $month);
        $currentEnrollments = $studentProfile->enrollments()->activeOn(today())->with(['course', 'teacherProfile', 'venue'])->get();
        $pricingQuotes = $currentEnrollments->mapWithKeys(
            fn ($enrollment): array => [$enrollment->id => $pricingService->forEnrollment($enrollment, today())],
        );
        $hasInactiveFlexWarning = $flexInactivityWarning->appliesTo($studentProfile);

        return view('staff.students.show', compact(
            'studentProfile',
            'upcomingReservations',
            'recentReservations',
            'month',
            'monthlySummary',
            'currentEnrollments',
            'pricingQuotes',
            'hasInactiveFlexWarning',
        ));
    }
}
