<?php

namespace App\Http\Controllers\Student;

use App\Enums\InquiryCategory;
use App\Enums\InquiryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInquiryRequest;
use App\Models\Inquiry;
use App\Services\MusakoNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InquiryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Inquiry::class);
        $inquiries = $request->user()->studentProfile->inquiries()->latest('requested_at')->get();

        return view('student.inquiries.index', ['inquiries' => $inquiries, 'categories' => InquiryCategory::cases()]);
    }

    public function show(Inquiry $inquiry): View
    {
        Gate::authorize('view', $inquiry);

        return view('student.inquiries.show', compact('inquiry'));
    }

    public function store(StoreInquiryRequest $request, MusakoNotificationService $notifications): RedirectResponse
    {
        $inquiry = $request->user()->studentProfile->inquiries()->create([
            ...$request->validated(),
            'status' => InquiryStatus::Open,
            'requested_at' => now(),
        ]);
        $notifications->inquirySubmitted($inquiry);

        return redirect()->route('student.inquiries.index')->with('success', 'お問い合わせを送信しました。');
    }
}
