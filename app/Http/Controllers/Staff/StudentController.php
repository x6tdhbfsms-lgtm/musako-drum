<?php

namespace App\Http\Controllers\Staff;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentController extends Controller
{
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

    public function show(StudentProfile $student): View
    {
        Gate::authorize('view', $student);
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

        return view('staff.students.show', compact('studentProfile', 'upcomingReservations', 'recentReservations'));
    }
}
