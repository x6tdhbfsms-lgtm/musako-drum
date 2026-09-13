<?php

namespace App\Http\Controllers\Staff;

use App\Enums\InquiryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateInquiryRequest;
use App\Models\Inquiry;
use App\Models\User;
use App\Services\MusakoNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class InquiryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Inquiry::class);
        $inquiries = Inquiry::query()->with('studentProfile.user')
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->latest('requested_at')->paginate(30);

        return view('staff.inquiries.index', compact('inquiries'));
    }

    public function show(Inquiry $inquiry): View
    {
        Gate::authorize('view', $inquiry);
        $inquiry->load(['studentProfile.user', 'handler']);

        return view('staff.inquiries.show', compact('inquiry'));
    }

    public function update(
        UpdateInquiryRequest $request,
        Inquiry $inquiry,
        MusakoNotificationService $notifications,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $previousStatus = $inquiry->status;
        $inquiry->update([
            ...$request->validated(),
            'handled_by_user_id' => $user->id,
            'status_updated_at' => now(),
        ]);
        if ($previousStatus !== InquiryStatus::Resolved && $inquiry->status === InquiryStatus::Resolved) {
            $notifications->inquiryResolved($inquiry);
        }

        return redirect()->route('staff.inquiries.show', $inquiry)->with('success', 'お問い合わせの状態を更新しました。');
    }
}
