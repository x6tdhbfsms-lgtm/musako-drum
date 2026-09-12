<?php

namespace App\Models;

use App\Enums\InquiryCategory;
use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id', 'category', 'subject', 'body', 'status', 'requested_at',
        'handled_by_user_id', 'status_updated_at', 'staff_note',
    ];

    protected function casts(): array
    {
        return [
            'category' => InquiryCategory::class,
            'status' => InquiryStatus::class,
            'requested_at' => 'datetime',
            'status_updated_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }
}
