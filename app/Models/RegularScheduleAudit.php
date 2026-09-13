<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegularScheduleAudit extends Model
{
    public $timestamps = false;

    protected $fillable = ['regular_schedule_batch_id', 'regular_schedule_occurrence_id', 'actor_user_id', 'action', 'before_values', 'after_values', 'created_at'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'created_at' => 'datetime'];
    }
}
