<?php

namespace App\Http\Controllers;

use App\Actions\CreateTrialLessonRequest;
use App\Enums\LessonSlotAudience;
use App\Enums\LessonSlotStatus;
use App\Http\Requests\StoreTrialLessonRequest;
use App\Models\LessonSlot;
use App\Models\TrialLessonRequest;
use App\Services\LessonSlotCapacityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PublicTrialLessonController extends Controller
{
    public function index(LessonSlotCapacityService $capacity): View
    {
        $lessonSlots = LessonSlot::query()
            ->with(['teacherProfile', 'venue', 'course'])
            ->where('status', LessonSlotStatus::Open)
            ->whereIn('booking_audience', [LessonSlotAudience::Trial, LessonSlotAudience::Both])
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(60)
            ->get()
            ->filter(fn (LessonSlot $slot): bool => $capacity->availableForTrialApplication($slot) > 0)
            ->values();

        return view('public.trial-lessons.index', compact('lessonSlots'));
    }

    public function store(StoreTrialLessonRequest $request, CreateTrialLessonRequest $create): RedirectResponse
    {
        $result = $create->handle($request->safe()->except(['privacy_accepted', 'website']));

        return redirect()->route('trial-lessons.complete', $result['request']->public_reference)
            ->with('trial_access_token', $result['token']);
    }

    public function complete(string $reference): View
    {
        $trialLessonRequest = TrialLessonRequest::query()->where('public_reference', $reference)->firstOrFail();

        return view('public.trial-lessons.complete', compact('trialLessonRequest'));
    }
}
