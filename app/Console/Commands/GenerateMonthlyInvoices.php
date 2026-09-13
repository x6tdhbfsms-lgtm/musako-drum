<?php

namespace App\Console\Commands;

use App\Models\BillingSetting;
use App\Services\MonthlyInvoiceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'billing:generate-next-month {--force : 設定日時に関係なく実行する}';

    protected $description = '翌月分の月次請求下書きを安全に生成します';

    public function handle(MonthlyInvoiceGenerator $generator): int
    {
        $setting = BillingSetting::current();
        $now = CarbonImmutable::now('Asia/Tokyo');
        if (! $this->option('force') && (! $setting->auto_generate_enabled
            || $now->day !== $setting->generation_day
            || $now->format('H:i') !== substr((string) $setting->generation_time, 0, 5))) {
            $this->components->info('請求自動生成の設定日時ではないため、処理を行いませんでした。');

            return self::SUCCESS;
        }

        $month = $now->addMonthNoOverflow()->startOfMonth();
        $invoices = $generator->generate($month);
        $this->components->info($month->format('Y年n月').'分の請求下書きを'.$invoices->count().'件確認しました。');

        return self::SUCCESS;
    }
}
