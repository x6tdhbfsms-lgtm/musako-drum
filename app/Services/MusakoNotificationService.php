<?php

namespace App\Services;

use App\Enums\AdmissionApplicationStatus;
use App\Enums\ApplicationStatus;
use App\Enums\InquiryStatus;
use App\Enums\ReservationStatus;
use App\Enums\TrialLessonStatus;
use App\Models\AdmissionApplication;
use App\Models\AttendanceNotice;
use App\Models\ContractChangeRequest;
use App\Models\Inquiry;
use App\Models\MembershipStatusRequest;
use App\Models\PaymentMethodChangeRequest;
use App\Models\PersonalInformationChangeRequest;
use App\Models\RegularScheduleNotificationDelivery;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use App\Models\TrialLessonRequest;
use App\Models\User;
use App\Notifications\AdmissionApplicationNotification;
use App\Notifications\AttendanceNoticeNotification;
use App\Notifications\Concerns\QueuedMusakoNotification;
use App\Notifications\ContractChangeApplicationNotification;
use App\Notifications\InitialPasswordSetupNotification;
use App\Notifications\InquiryNotification;
use App\Notifications\MembershipStatusApplicationNotification;
use App\Notifications\PaymentMethodChangeApplicationNotification;
use App\Notifications\PersonalInformationChangeApplicationNotification;
use App\Notifications\RegularScheduleConfirmedNotification;
use App\Notifications\ReservationApplicationNotification;
use App\Notifications\ReservationCancelledNotification;
use App\Notifications\TransferApplicationNotification;
use App\Notifications\TrialLessonApplicationNotification;
use Carbon\CarbonImmutable;
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

    public function regularScheduleConfirmed(StudentProfile $student, CarbonImmutable $month): void
    {
        $month = $month->startOfMonth();
        $signature = hash('sha256', $student->reservationRequests()
            ->whereDate('lesson_entitlement_month', $month)
            ->whereNotNull('regular_schedule_occurrence_id')
            ->with('lessonSlot:id,starts_at,ends_at')
            ->get()->sortBy('lessonSlot.starts_at')
            ->map(fn (ReservationRequest $reservation) => $reservation->id.'|'.$reservation->lessonSlot->starts_at->toIso8601String())
            ->implode(';'));
        $delivery = RegularScheduleNotificationDelivery::query()
            ->where('student_profile_id', $student->id)
            ->whereDate('entitlement_month', $month)
            ->where('schedule_signature', $signature)
            ->first();
        if ($delivery === null) {
            $delivery = RegularScheduleNotificationDelivery::query()->create([
                'student_profile_id' => $student->id,
                'entitlement_month' => $month->toDateString(),
                'schedule_signature' => $signature,
                'notified_at' => now(),
            ]);
        }

        if ($delivery->wasRecentlyCreated) {
            $this->sendToStudent($student->user, new RegularScheduleConfirmedNotification($student, $month));
        }
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

    public function trialSubmitted(TrialLessonRequest $trialLessonRequest, string $accessToken): void
    {
        $this->sendToMail(
            $trialLessonRequest->email,
            new TrialLessonApplicationNotification($trialLessonRequest, false, $accessToken),
        );
        $this->send(
            $this->recipients->forTrial($trialLessonRequest),
            new TrialLessonApplicationNotification($trialLessonRequest, true),
        );
    }

    public function trialReviewed(TrialLessonRequest $trialLessonRequest, TrialLessonStatus $decision): void
    {
        $this->sendToMail($trialLessonRequest->email, new TrialLessonApplicationNotification($trialLessonRequest, false));
    }

    public function trialCancelled(TrialLessonRequest $trialLessonRequest): void
    {
        $this->send(
            $this->recipients->forTrial($trialLessonRequest),
            new TrialLessonApplicationNotification($trialLessonRequest, true),
        );
    }

    public function admissionSubmitted(AdmissionApplication $application): void
    {
        $this->sendToMail($application->email, new AdmissionApplicationNotification($application, false));
        $this->send($this->recipients->allStaff(), new AdmissionApplicationNotification($application, true));
    }

    public function admissionReviewed(AdmissionApplication $application, AdmissionApplicationStatus $decision, ?string $passwordToken = null): void
    {
        $this->sendToMail($application->email, new AdmissionApplicationNotification($application, false));
        if ($decision === AdmissionApplicationStatus::Approved && $passwordToken !== null) {
            $this->sendToMail($application->email, new InitialPasswordSetupNotification($application->email_normalized, $passwordToken));
        }
    }

    private function sendToStudent(User $student, QueuedMusakoNotification $notification): void
    {
        $this->send(collect([$student]), $notification);
    }

    private function sendToMail(string $email, QueuedMusakoNotification $notification): void
    {
        try {
            Notification::route('mail', $email)->notify(
                $notification->onQueue((string) config('musako.notifications.queue'))->afterCommit(),
            );
        } catch (Throwable $exception) {
            Log::warning('MUSAKO public email notification could not be queued.', [
                'notification' => $notification::class,
                'exception' => $exception::class,
            ]);
        }
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
