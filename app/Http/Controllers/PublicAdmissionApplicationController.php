<?php

namespace App\Http\Controllers;

use App\Actions\CreateAdmissionApplication;
use App\Enums\LessonType;
use App\Enums\PricingCategory;
use App\Http\Requests\StoreAdmissionApplicationRequest;
use App\Models\AdmissionApplication;
use App\Models\Course;
use App\Models\PricingSetting;
use App\Models\TeacherProfile;
use App\Models\TrialLessonRequest;
use App\Models\Venue;
use App\Services\LessonPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PublicAdmissionApplicationController extends Controller
{
    public function create(string $reference, string $token, LessonPricingService $pricing): View
    {
        $trialLessonRequest = $this->authorizedTrial($reference, $token);
        $course = $trialLessonRequest->lessonSlot->course ?? Course::query()->where('is_active', true)->first();
        $count = $course?->default_monthly_lessons ?? 2;
        $quote = $pricing->quote(CarbonImmutable::today(config('app.timezone')), LessonType::Regular, $count, PricingCategory::Standard);

        return view('public.admissions.create', [
            'trialLessonRequest' => $trialLessonRequest,
            'token' => $token,
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(),
            'venues' => Venue::query()->where('is_active', true)->orderBy('name')->get(),
            'teachers' => TeacherProfile::query()->where('is_accepting_bookings', true)->orderBy('display_name')->get(),
            'defaultCourse' => $course,
            'quote' => $quote,
            'pricingSetting' => PricingSetting::query()->effectiveOn(today())->first(),
        ]);
    }

    public function store(string $reference, string $token, StoreAdmissionApplicationRequest $request, CreateAdmissionApplication $create): RedirectResponse
    {
        $trialLessonRequest = $this->authorizedTrial($reference, $token);
        $result = $create->handle($trialLessonRequest, $request->safe()->except(['privacy_accepted', 'website']));

        return redirect()->route('admissions.complete', $result['application']->public_reference);
    }

    public function complete(string $reference): View
    {
        $application = AdmissionApplication::query()->where('public_reference', $reference)->firstOrFail();

        return view('public.admissions.complete', compact('application'));
    }

    private function authorizedTrial(string $reference, string $token): TrialLessonRequest
    {
        $trial = TrialLessonRequest::query()->with('lessonSlot.course')->where('public_reference', $reference)->firstOrFail();
        abort_unless($trial->tokenMatches($token), 404);

        return $trial;
    }
}
