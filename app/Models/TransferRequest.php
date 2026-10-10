<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'original_reservation_request_id',
        'requested_lesson_slot_id',
        'resulting_reservation_request_id',
        'status',
        'reason',
        'student_note',
        'staff_note',
        'terms_snapshot',
        'requested_at',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'terms_snapshot' => 'array',
            'status' => ApplicationStatus::class,
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isAwaitingSchedule(): bool
    {
        return $this->status === ApplicationStatus::Approved
            && $this->requested_lesson_slot_id === null
            && $this->resulting_reservation_request_id === null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            ApplicationStatus::Pending => $this->requested_lesson_slot_id === null ? '保留申請中' : '承認待ち',
            ApplicationStatus::Approved => $this->isAwaitingSchedule() ? '日程保留中' : '振替確定',
            ApplicationStatus::Rejected => '却下',
        };
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function originalReservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class, 'original_reservation_request_id');
    }

    public function requestedLessonSlot(): BelongsTo
    {
        return $this->belongsTo(LessonSlot::class, 'requested_lesson_slot_id');
    }

    public function resultingReservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class, 'resulting_reservation_request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
