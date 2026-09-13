<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegularScheduleNotificationDelivery extends Model
{
    protected $fillable = ['student_profile_id', 'entitlement_month', 'schedule_signature', 'notified_at'];

    protected function casts(): array
    {
        return ['entitlement_month' => 'date', 'notified_at' => 'datetime'];
    }
}
