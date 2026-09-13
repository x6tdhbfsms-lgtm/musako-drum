<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Models\AttendanceNotice;
use App\Models\ContractChangeRequest;
use App\Models\Inquiry;
use App\Models\MembershipStatusRequest;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\ReservationRequest;
use App\Models\TransferRequest;
use App\Models\User;
use App\Notifications\AttendanceNoticeNotification;
use App\Notifications\Concerns\QueuedMusakoNotification;
use App\Notifications\ContractChangeApplicationNotification;
use App\Notifications\InquiryNotification;
use App\Notifications\MembershipStatusApplicationNotification;
use App\Notifications\PaymentMethodChangeApplicationNotification;
use App\Notifications\PersonalInformationChangeApplicationNotification;
use App\Notifications\ReservationApplicationNotification;
use App\Notifications\ReservationCancelledNotification;
use App\Notifications\TransferApplicationNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class MusakoNotificationService
{
    public function __construct(private readonly NotificationRecipientResolver $recipients) {}

    public function reservationSubmitted(ReservationRequest $reservationRequest): void
    {
        $this->send(
            $this->recipients->forLessonSlot($reservationRequest->lessonSlot),
            new ReservationApplicationNotification($reservationRequest, ReservationStatus::Pending),
        );
    }

    public function reservationReviewed(ReservationRequest $reservationRequest, ReservationStatus $decision): void
    {
        $this->sendToStudent(
            $reservationRequest->studentProfile->user,
            new ReservationApplicationNotification($reservationRequest, $decision),
        );
    }

    public function reservationCancelled(ReservationRequest $reservationRequest): void
    {
        $this->send(
            $this->recipients->forLessonSlot($reservationRequest->lessonSlot),
            new ReservationCancelledNotification($reservationRequest),
        );
    }

    public function attendanceNoticeChanged(AttendanceNotice $attendanceNotice): void
    {
        $this->send(
            $this->recipients->forLessonSlot($attendanceNotice->reservationRequest->lessonSlot),
            new AttendanceNoticeNotification($attendanceNotice),
        );
    }

    public function transferSubmitted(TransferRequest $transferRequest): void
    {
        $this->send(
            $this->recipients->forTransfer($transferRequest),
            new TransferApplicationNotification($transferRequest, ApplicationStatus::Pending),
        );
    }

    public function transferReviewed(TransferRequest $transferRequest, ApplicationStatus $decision): void
    {
        $this->sendToStudent(
            $transferRequest->studentProfile->user,
            new TransferApplicationNotification($transferRequest, $decision),
        );
    }

    public function membershipSubmitted(MembershipStatusRequest $membershipStatusRequest): void
    {
        $this->send(
            $this->recipients->forStudent($membershipStatusRequest->studentProfile),
            new MembershipStatusApplicationNotification($membershipStatusRequest, ApplicationStatus::Pending),
        );
    }

    public function membershipReviewed(MembershipStatusRequest $membershipStatusRequest, ApplicationStatus $decision): void
    {
        $this->sendToStudent(
            $membershipStatusRequest->studentProfile->user,
            new MembershipStatusApplicationNotification($membershipStatusRequest, $decision),
        );
    }

    public function contractChangeSubmitted(ContractChangeRequest $contractChangeRequest): void
    {
        $this->send(
            $this->recipients->forStudent($contractChangeRequest->studentProfile),
            new ContractChangeApplicationNotification($contractChangeRequest, ApplicationStatus::Pending),
        );
    }

    public function contractChangeReviewed(ContractChangeRequest $contractChangeRequest, ApplicationStatus $decision): void
    {
        $this->sendToStudent(
            $contractChangeRequest->studentProfile->user,
            new ContractChangeApplicationNotification($contractChangeRequest, $decision),
        );
    }

    public function personalInformationChangeSubmitted(PersonalInformationChangeRequest $request): void
    {
        $this->send(
            $this->recipients->forStudent($request->studentProfile),
            new PersonalInformationChangeApplicationNotification($request, ApplicationStatus::Pending),
        );
    }

    public function personalInformationChangeReviewed(PersonalInformationChangeRequest $request, ApplicationStatus $decision): void
    {
        $this->sendToStudent(
            $request->studentProfile->user,
            new PersonalInformationChangeApplicationNotification($request, $decision),
        );
    }

    public function paymentMethodChangeSubmitted(PaymentMethodChangeRequest $request): void
    {
        $this->send(
            $this->recipients->forStudent($request->studentProfile),
            new PaymentMethodChangeApplicationNotification($request, ApplicationStatus::Pending),
        );
    }

    public function paymentMethodChangeReviewed(PaymentMethodChangeRequest $request, ApplicationStatus $decision): void
    {
        $this->sendToStudent(
            $request->studentProfile->user,
            new PaymentMethodChangeApplicationNotification($request, $decision),
        );
    }

    public function inquirySubmitted(Inquiry $inquiry): void
    {
        $this->send(
            $this->recipients->forStudent($inquiry->studentProfile),
            new InquiryNotification($inquiry, InquiryStatus::Open),
        );
    }

    public function inquiryResolved(Inquiry $inquiry): void
    {
        $this->sendToStudent(
            $inquiry->studentProfile->user,
            new InquiryNotification($inquiry, InquiryStatus::Resolved),
        );
    }

    private function sendToStudent(User $student, QueuedMusakoNotification $notification): void
    {
        $this->send(collect([$student]), $notification);
    }

    /** @param Collection<int, User> $recipients */
    private function send(Collection $recipients, QueuedMusakoNotification $notification): void
    {
        try {
            Notification::send(
                $recipients,
                $notification->onQueue((string) config('musako.notifications.queue'))->afterCommit(),
            );
        } catch (Throwable $exception) {
            Log::warning('MUSAKO email notification could not be queued.', [
                'notification' => $notification::class,
                'exception' => $exception::class,
            ]);
        }
    }
}
