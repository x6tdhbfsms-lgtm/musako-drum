<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class LessonOperatingHours
{
    public const DESCRIPTION = '平日12:00〜21:00、土日10:00〜20:00';

    /** @return array{0: int, 1: int} */
    public static function hours(CarbonInterface $date): array
    {
        return $date->isWeekend() ? [10, 20] : [12, 21];
    }

    public static function allows(CarbonInterface $start, CarbonInterface $end): bool
    {
        [$opening, $closing] = self::hours($start);

        return $end->gt($start)
            && $start->gte($start->copy()->startOfDay()->setHour($opening))
            && $end->lte($start->copy()->startOfDay()->setHour($closing));
    }

    public static function assertAllowed(CarbonInterface $start, CarbonInterface $end, string $key = 'starts_at'): void
    {
        if (! self::allows($start, $end)) {
            throw ValidationException::withMessages([$key => 'レッスンは'.self::DESCRIPTION.'の時間内に終了するよう設定してください。']);
        }
    }
}
