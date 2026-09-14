<?php

namespace Tests\Feature;

use App\Actions\CancelMonthlyInvoice;
use App\Actions\ConfirmMonthlyInvoice;
use App\Actions\ReissueMonthlyInvoice;
use App\Models\LessonEnrollment;
use App\Models\MonthlyInvoice;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\MonthlyInvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class InvoiceReissueConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    #[RequiresPhpExtension('pcntl')]
    public function test_two_mysql_processes_reissuing_the_same_invoice_create_one_draft(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Two-process row-lock verification requires MySQL.');
        }
        $this->travelTo('2026-09-14 12:00:00');
        Notification::fake();
        $this->seed();
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create([
            'starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active',
            'monthly_lesson_limit' => 2, 'lesson_type' => 'regular', 'pricing_category' => 'standard',
        ]);
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin, true);
        app(CancelMonthlyInvoice::class)->handle($invoice, $admin, '訂正');
        DB::purge();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                app(ReissueMonthlyInvoice::class)->handle($invoice, $admin, '同時要求');
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }
        $draft = app(ReissueMonthlyInvoice::class)->handle($invoice, $admin, '同時要求');
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(1, MonthlyInvoice::where('reissued_from_invoice_id', $invoice->id)->count());
        $this->assertSame(1, MonthlyInvoice::where('student_profile_id', $student->id)->where('status', '!=', 'cancelled')->count());
        $this->assertSame($draft->id, $invoice->reissues()->sole()->id);
    }
}
