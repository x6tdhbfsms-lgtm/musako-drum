<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

class RegularScheduleConfirmedNotification extends Concerns\QueuedMusakoNotification
{
    public function __construct(public StudentProfile $studentProfile, public CarbonImmutable $month) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Reservation;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reservations = $this->studentProfile->reservationRequests()
            ->whereDate('lesson_entitlement_month', $this->month->startOfMonth())
            ->whereNotNull('regular_schedule_occurrence_id')
            ->with(['lessonSlot.teacherProfile', 'lessonSlot.venue'])
            ->get()->sortBy('lessonSlot.starts_at');

        $message = (new MailMessage)
            ->subject('【MUSAKOドラム教室】'.$this->month->format('n月').'分のレッスン予定が確定しました')
            ->greeting($this->month->format('n月').'分のレッスン予定');
        foreach ($reservations as $reservation) {
            $message->line($reservation->lessonSlot->starts_at->format('n/j H:i').'〜 '.$reservation->lessonSlot->teacherProfile->display_name.'／'.($reservation->lessonSlot->venue?->name ?? '会場未定'));
        }

        return $message->action('マイページで確認する', route('student.dashboard', ['month' => $this->month->format('Y-m')]));
    }
}
