<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'student_number', 'phone', 'postal_code', 'address', 'joined_on'];

    protected function casts(): array
    {
        return ['joined_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(LessonEnrollment::class);
    }

    public function reservationRequests(): HasMany
    {
        return $this->hasMany(ReservationRequest::class);
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(TransferRequest::class);
    }

    public function membershipStatusRequests(): HasMany
    {
        return $this->hasMany(MembershipStatusRequest::class);
    }

    public function contractChangeRequests(): HasMany
    {
        return $this->hasMany(ContractChangeRequest::class);
    }

    public function personalInformationChangeRequests(): HasMany
    {
        return $this->hasMany(PersonalInformationChangeRequest::class);
    }

    public function paymentMethodChangeRequests(): HasMany
    {
        return $this->hasMany(PaymentMethodChangeRequest::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }
}
