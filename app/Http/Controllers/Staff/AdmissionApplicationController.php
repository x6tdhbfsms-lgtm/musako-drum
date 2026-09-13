<?php

namespace App\Http\Controllers\Staff;

use App\Actions\ReviewAdmissionApplication;
use App\Enums\AdmissionApplicationStatus;
use App\Enums\PricingCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewAdmissionApplicationRequest;
use App\Models\AdmissionApplication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdmissionApplicationController extends Controller
{
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
