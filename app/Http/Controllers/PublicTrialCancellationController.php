<?php

namespace App\Http\Controllers;

use App\Actions\CancelTrialLessonRequest;
use App\Models\TrialLessonRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PublicTrialCancellationController extends Controller
{
    public function show(string $reference, string $token): View
    {
        $trialLessonRequest = $this->authorizedRequest($reference, $token);

        return view('public.trial-lessons.manage', compact('trialLessonRequest', 'token'));
    }

    public function destroy(string $reference, string $token, CancelTrialLessonRequest $cancel): RedirectResponse
    {
        $trialLessonRequest = $this->authorizedRequest($reference, $token);
        $cancel->handle($trialLessonRequest);

        return redirect()->route('trial-lessons.complete', $reference)->with('success', '体験レッスンをキャンセルしました。');
    }

    private function authorizedRequest(string $reference, string $token): TrialLessonRequest
    {
        $request = TrialLessonRequest::query()->where('public_reference', $reference)->firstOrFail();
        abort_unless($request->tokenMatches($token), 404);

        return $request;
    }
}
