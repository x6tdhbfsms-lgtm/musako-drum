<?php

namespace App\Notifications;

use App\Enums\AttendanceNoticeType;
use App\Enums\NotificationCategory;
use App\Models\AttendanceNotice;
use App\Notifications\Concerns\QueuedMusakoNotification;
use Illuminate\Notifications\Messages\MailMessage;

class AttendanceNoticeNotification extends QueuedMusakoNotification
{
    public function __construct(public AttendanceNotice $attendanceNotice) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Attendance;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->attendanceNotice->loadMissing([
            'reservationRequest.studentProfile.user',
            'reservationRequest.lessonSlot.teacherProfile',
            'reservationRequest.lessonSlot.venue',
            'reservationRequest.lessonSlot.course',
        ]);
        $reservation = $this->attendanceNotice->reservationRequest;
        $isLate = $this->attendanceNotice->type === AttendanceNoticeType::Late;
        $mail = (new MailMessage)
            ->subject('【MUSAKOドラム教室】'.($isLate ? '遅刻' : 'お休み').'連絡があります')
            ->greeting(($isLate ? '遅刻' : 'お休み').'連絡が登録・更新されました')
            ->line('生徒：'.$reservation->studentProfile->user->name)
            ->lines($this->lessonDetails($reservation->lessonSlot));

        if ($isLate && $this->attendanceNotice->late_minutes !== null) {
            $mail->line('遅刻予定：'.$this->attendanceNotice->late_minutes.'分');
        }
        if ($isLate && $this->attendanceNotice->expected_arrival_at !== null) {
            $mail->line('到着予定：'.$this->attendanceNotice->expected_arrival_at->setTimezone(config('app.timezone'))->format('H:i'));
        }
        if (filled($this->attendanceNotice->notes)) {
            $mail->line('備考：'.$this->attendanceNotice->notes);
        }

        return $mail->action('予約詳細を確認する', route('staff.reservations.show', $reservation));
    }
}
