<?php

namespace App\Console\Commands;

use App\Models\RegularScheduleSetting;
use App\Services\RegularScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateRegularSchedules extends Command
{
    protected $signature = 'regular-schedules:generate-next-month {--month= : Generate YYYY-MM instead} {--force : Ignore enabled setting}';

    protected $description = 'Generate and confirm regular lesson schedules through two months ahead';

    public function handle(RegularScheduleGenerator $generator): int
    {
        $setting = RegularScheduleSetting::current();
        if (! $this->option('force') && ! $setting->automatic_generation_enabled) {
            $this->components->info('Automatic regular schedule generation is not due.');

            return self::SUCCESS;
        }

        $today = CarbonImmutable::today(config('app.timezone'));
        $months = $this->option('month')
            ? [CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'), config('app.timezone'))]
            : [$today->startOfMonth(), $today->startOfMonth()->addMonth(), $today->startOfMonth()->addMonths(2)];
        $hasErrors = false;
        foreach ($months as $month) {
            $batches = $generator->generateMonth($month, notBefore: $this->option('month') ? null : $today);
            foreach ($generator->confirmGenerated($batches) as $error) {
                $hasErrors = true;
                $this->components->error($error);
            }
            $this->components->info($month->format('Y-m').": {$batches->count()} regular schedule batches processed.");
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
