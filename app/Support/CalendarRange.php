<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

class CalendarRange
{
    public function __construct(
        public readonly CarbonImmutable $focus,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $view,
    ) {}

    public static function month(CarbonImmutable $focus): self
    {
        $month = $focus->startOfMonth();

        return new self(
            $month,
            $month->startOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
            $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY)->endOfDay(),
            'month',
        );
    }

    public static function forView(CarbonImmutable $focus, string $view): self
    {
        return match ($view) {
            'week' => new self(
                $focus,
                $focus->startOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
                $focus->endOfWeek(CarbonImmutable::SATURDAY)->endOfDay(),
                $view,
            ),
            'day' => new self($focus, $focus->startOfDay(), $focus->endOfDay(), $view),
            default => self::month($focus),
        };
    }

    public function days(): CarbonPeriod
    {
        return CarbonPeriod::create($this->start->startOfDay(), $this->end->startOfDay());
    }

    public function previousFocus(): CarbonImmutable
    {
        return match ($this->view) {
            'week' => $this->focus->subWeek(),
            'day' => $this->focus->subDay(),
            default => $this->focus->subMonth(),
        };
    }

    public function nextFocus(): CarbonImmutable
    {
        return match ($this->view) {
            'week' => $this->focus->addWeek(),
            'day' => $this->focus->addDay(),
            default => $this->focus->addMonth(),
        };
    }
}
