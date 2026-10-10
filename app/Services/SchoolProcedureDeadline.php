<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SchoolProcedureDeadline
{
    public function assertInTime(mixed $effectiveOn): void
    {
        $deadline = CarbonImmutable::parse($effectiveOn, config('app.timezone'))->startOfMonth()->subMonth()->day(10)->endOfDay();
        if (CarbonImmutable::now(config('app.timezone'))->gt($deadline)) {
            throw ValidationException::withMessages(['effective_on' => '時間割・回数変更、休会・退会の申請期限は希望月の前月10日までです。']);
        }
    }
}
