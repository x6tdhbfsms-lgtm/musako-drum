<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MonthlyLessonSummary
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    public function __construct(
        public readonly CarbonImmutable $month,
        public readonly int $contracted,
        public readonly int $completed,
        public readonly int $confirmed,
        public readonly int $pending,
        public readonly int $transferScheduled,
        public readonly int $absent,
        public readonly int $cancelled,
        public readonly int $used,
        public readonly int $remaining,
        public readonly Collection $items,
    ) {}
}
