<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\MembershipRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipStatusRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'type',
        'effective_on',
        'reason',
        'student_note',
        'status',
        'requested_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'staff_note',
        'previous_enrollment_statuses',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MembershipRequestType::class,
            'effective_on' => 'date',
            'status' => ApplicationStatus::class,
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'previous_enrollment_statuses' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
