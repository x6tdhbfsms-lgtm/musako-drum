<?php

namespace App\Console\Commands;

use App\Models\RegularScheduleSetting;
use App\Services\RegularScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateRegularSchedules extends Command
{
    protected $signature = 'regular-schedules:generate-next-month {--month= : Generate YYYY-MM instead} {--force : Ignore enabled setting and generation day}';

    protected $description = 'Generate safe draft regular lesson schedules for the next month';

    public function handle(RegularScheduleGenerator $generator): int
    {
        $setting = RegularScheduleSetting::current();
        if (! $this->option('force') && (! $setting->automatic_generation_enabled || now(config('app.timezone'))->day !== $setting->generation_day)) {
            $this->components->info('Automatic regular schedule generation is not due.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'), config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'))->addMonth()->startOfMonth();
        $batches = $generator->generateMonth($month);
        $this->components->info($month->format('Y-m').": {$batches->count()} regular schedule batches processed.");

        return self::SUCCESS;
    }
}
