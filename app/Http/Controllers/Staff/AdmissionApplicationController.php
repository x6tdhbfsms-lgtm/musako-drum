<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewAdmissionApplication;
use App\Enums\AdmissionApplicationStatus;
use App\Enums\PricingCategory;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewAdmissionApplicationRequest;
use App\Http\Requests\StoreStaffAdmissionRequest;
use App\Models\AdmissionApplication;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdmissionApplicationController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);

        return view('staff.admissions.create', [
            'courses' => Course::where('is_active', true)->orderBy('name')->get(),
            'venues' => Venue::where('is_active', true)->orderBy('name')->get(),
            'teachers' => TeacherProfile::orderBy('display_name')->get(),
            'defaultCourse' => Course::where('code', 'INDIVIDUAL-60')->first(),
        ]);
    }

    public function store(StoreStaffAdmissionRequest $request, ReviewAdmissionApplication $review): RedirectResponse
    {
        $application = DB::transaction(function () use ($request, $review) {
            $attributes = $request->safe()->except(['privacy_accepted', 'website']);
            if ($attributes['lesson_type'] !== 'regular') {
                $attributes['weekday'] = null;
                $attributes['starts_at_time'] = null;
            }
            $application = AdmissionApplication::create([
                ...$attributes,
                'public_reference' => 'AD-'.Str::upper(Str::random(16)),
                'access_token_hash' => hash('sha256', Str::random(64)),
                'email_normalized' => $attributes['email'],
                'pending_email_key' => hash('sha256', $attributes['email']),
                'status' => AdmissionApplicationStatus::Pending,
                'privacy_policy_version' => config('musako.privacy.policy_version'),
                'privacy_consented_at' => now(),
                'requested_at' => now(),
                'notes' => '【管理者による直接登録】本人同意確認済み。'.($attributes['notes'] ?? ''),
            ]);

            return $review->handle($application, $request->user(), AdmissionApplicationStatus::Approved, null);
        }, 3);

        return redirect()->route('staff.students.show', $application->converted_student_profile_id)
            ->with('success', '生徒と契約を登録しました。初回パスワード設定の案内をメールで送信します。定期枠はスケジュール管理から生成・確認してください。');
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AdmissionApplication::class);
        $status = $request->string('status')->toString();
        $applications = AdmissionApplication::query()
            ->with(['course', 'teacherProfile', 'venue', 'trialLessonRequest'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('requested_at')
            ->paginate(25)
            ->withQueryString();

        return view('staff.admissions.index', compact('applications', 'status'));
    }

    public function show(AdmissionApplication $admissionApplication): View
    {
        Gate::authorize('view', $admissionApplication);
        $admissionApplication->load(['course', 'teacherProfile', 'venue', 'trialLessonRequest', 'processedBy', 'convertedStudentProfile']);

        return view('staff.admissions.show', compact('admissionApplication'));
    }

    public function update(ReviewAdmissionApplicationRequest $request, AdmissionApplication $admissionApplication, ReviewAdmissionApplication $review): RedirectResponse
    {
        $review->handle(
            $admissionApplication,
            $request->user(),
            AdmissionApplicationStatus::from($request->validated('status')),
            $request->validated('rejection_reason'),
            $request->validated('pricing_category') === null ? null : PricingCategory::from($request->validated('pricing_category')),
        );

        return back()->with('success', '入会申込みを更新しました。');
    }
}
