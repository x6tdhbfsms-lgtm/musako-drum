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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        try {
            $application = DB::transaction(function () use ($request, $review) {
                $attributes = $request->safe()->except(['privacy_accepted', 'website', 'regular_week_pattern', 'password', 'password_confirmation']);
                if ($attributes['lesson_type'] !== 'regular') {
                    $attributes['weekday'] = null;
                    $attributes['starts_at_time'] = null;
                }
                $application = AdmissionApplication::create([
                    ...$attributes,
                    'public_reference' => 'AD-'.Str::upper(Str::random(16)),
                    'access_token_hash' => hash('sha256', Str::random(64)),
                    'email' => null,
                    'email_normalized' => null,
                    'pending_login_name' => $attributes['login_name'],
                    'initial_password_hash' => Hash::make($request->validated('password')),
                    'name_kana' => $attributes['name_kana'] ?? '',
                    'phone' => $attributes['phone'] ?? '',
                    'status' => AdmissionApplicationStatus::Pending,
                    'privacy_policy_version' => config('musako.privacy.policy_version'),
                    'privacy_consented_at' => now(),
                    'requested_at' => now(),
                    'address' => $attributes['address'] ?? '',
                    'notes' => '【管理者による直接登録】本人同意確認済み。'.($attributes['notes'] ?? ''),
                ]);

                $weeks = $attributes['lesson_type'] === 'regular' && $request->filled('regular_week_pattern')
                    ? array_map('intval', explode(',', $request->validated('regular_week_pattern'))) : null;

                return $review->handle($application, $request->user(), AdmissionApplicationStatus::Approved, null, regularWeeks: $weeks);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'login_name')) {
                throw ValidationException::withMessages(['login_name' => 'このログイン名は使用済みです。別のログイン名を設定してください。']);
            }
            throw $exception;
        }

        return redirect()->route('staff.students.show', $application->converted_student_profile_id)
            ->with('success', '生徒と契約を登録しました。設定したログイン名とパスワードを生徒へ直接お伝えください。固定日時のあるレギュラー契約はレッスンを確定しました。');
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
