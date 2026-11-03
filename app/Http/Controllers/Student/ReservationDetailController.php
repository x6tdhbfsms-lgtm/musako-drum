<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ReservationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class ReservationDetailController extends Controller
{
    public function __invoke(ReservationRequest $reservationRequest): View
    {
        Gate::authorize('view', $reservationRequest);
        $reservationRequest->load([
            'lessonSlot.teacherProfile.user',
            'lessonSlot.venue',
            'lessonSlot.course',
            'attendanceNotice',
            'transferRequests.requestedLessonSlot',
            'resultingTransferRequest.originalReservationRequest.lessonSlot',
        ]);

        $history = collect();
        if ($reservationRequest->attendanceNotice !== null) {
            $notice = $reservationRequest->attendanceNotice;
            $history->push([
                'type' => 'attendance',
                'title' => $notice->type->value === 'absence' ? 'お休み連絡済み' : '遅刻連絡済み',
                'summary' => $notice->notes,
                'occurred_at' => $notice->submitted_at ?? $notice->created_at,
                'created_at' => $notice->created_at,
                'updated_at' => $notice->submitted_at,
            ]);
        }

        foreach ($reservationRequest->transferRequests as $transferRequest) {
            $history->push([
                'type' => 'transfer',
                'title' => '振替'.$transferRequest->statusLabel(),
                'summary' => '希望先：'.($transferRequest->requestedLessonSlot?->starts_at?->format('Y年n月j日 H:i') ?? '日程未定（保留）'),
                'occurred_at' => $transferRequest->reviewed_at ?? $transferRequest->requested_at ?? $transferRequest->created_at,
                'created_at' => $transferRequest->requested_at ?? $transferRequest->created_at,
                'updated_at' => $transferRequest->reviewed_at,
            ]);
        }

        if ($reservationRequest->resultingTransferRequest !== null) {
            $transferRequest = $reservationRequest->resultingTransferRequest;
            $history->push([
                'type' => 'transfer',
                'title' => '振替確定',
                'summary' => '振替元の予約日：'.($transferRequest->originalReservationRequest?->lessonSlot?->starts_at?->format('Y年n月j日 H:i') ?? '日付未設定'),
                'occurred_at' => $transferRequest->reviewed_at ?? $transferRequest->requested_at ?? $transferRequest->created_at,
                'created_at' => $transferRequest->requested_at ?? $transferRequest->created_at,
                'updated_at' => $transferRequest->reviewed_at,
            ]);
        }

        $history = $history->sortByDesc(fn (array $item): int => $item['occurred_at']->getTimestamp())->values();

        return view('student.reservations.show', [
            'reservationRequest' => $reservationRequest,
            'latestHistory' => $history->first(),
            'previousHistory' => $history->skip(1)->values(),
        ]);
    }
}
