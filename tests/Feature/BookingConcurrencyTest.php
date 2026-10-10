<?php

namespace Tests\Feature;

use App\Actions\ConfirmMonthlyInvoice;
use App\Actions\RegisterInvoicePayment;
use App\Actions\ReviewReservationRequest;
use App\Actions\ReviewTransferRequest;
use App\Enums\ApplicationStatus;
use App\Enums\ReservationStatus;
use App\Models\LessonEnrollment;
use App\Models\LessonSlot;
use App\Models\PaymentRecord;
use App\Models\ReservationRequest;
use App\Models\StudentProfile;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Venue;
use App\Services\MonthlyInvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pcntl')]
class BookingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent row-lock checks require MySQL.');
        }
        $this->travelTo('2026-10-01 12:00');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_processes_cannot_approve_more_than_slot_capacity(): void
    {
        $slot = LessonSlot::factory()->for(Venue::factory()->create(['lesson_room_type' => 'music_room']))->create();
        $first = ReservationRequest::factory()->for($slot)->create(['status' => 'pending']);
        $second = ReservationRequest::factory()->for($slot)->create(['status' => 'pending']);
        $admin = User::factory()->admin()->create();
        $results = $this->race(
            fn () => app(ReviewReservationRequest::class)->handle($first, $admin, ReservationStatus::Approved, null),
            fn () => app(ReviewReservationRequest::class)->handle($second, $admin, ReservationStatus::Approved, null),
        );
        $this->assertEqualsCanonicalizing([0, 2], $results);
        $this->assertSame(1, $slot->reservationRequests()->where('status', 'approved')->count());
        $this->assertSame(1, $slot->reservationRequests()->where('status', 'pending')->count());
    }

    public function test_two_processes_cannot_transfer_into_the_last_seat(): void
    {
        $venue = Venue::factory()->create(['lesson_room_type' => 'music_room']);
        $target = LessonSlot::factory()->for($venue)->create();
        $transfers = [];
        foreach ([15, 16] as $day) {
            $source = LessonSlot::factory()->for($venue)->create(['starts_at' => "2026-10-$day 12:00", 'ends_at' => "2026-10-$day 13:00"]);
            $original = ReservationRequest::factory()->for($source)->create(['status' => 'approved', 'studio_fee_amount' => 1000, 'studio_fee_priced_on' => "2026-10-$day"]);
            $transfers[] = TransferRequest::factory()->create([
                'student_profile_id' => $original->student_profile_id, 'original_reservation_request_id' => $original->id,
                'requested_lesson_slot_id' => $target->id, 'status' => 'pending',
            ]);
        }
        $admin = User::factory()->admin()->create();
        $results = $this->race(
            fn () => app(ReviewTransferRequest::class)->handle($transfers[0], $admin, ApplicationStatus::Approved, null),
            fn () => app(ReviewTransferRequest::class)->handle($transfers[1], $admin, ApplicationStatus::Approved, null),
        );
        $this->assertEqualsCanonicalizing([0, 2], $results);
        $this->assertSame(1, $target->reservationRequests()->where('status', 'approved')->count());
        $this->assertSame(1, TransferRequest::where('status', 'approved')->count());
        $this->assertSame(1, ReservationRequest::where('status', 'cancelled')->count());
    }

    public function test_two_processes_recording_the_same_payment_create_one_record(): void
    {
        $this->seed();
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create(['starts_on' => '2026-01-01', 'monthly_lesson_limit' => 2]);
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin, true);
        $data = ['idempotency_key' => 'concurrent-audit-payment', 'amount' => $invoice->fresh()->total_amount,
            'paid_on' => '2026-10-01', 'payment_method' => 'cash'];
        $pay = fn () => app(RegisterInvoicePayment::class)->handle($invoice, $admin, $data);
        $this->assertSame([0, 0], $this->race($pay, $pay));
        $this->assertSame(1, PaymentRecord::where('monthly_invoice_id', $invoice->id)->count());
        $this->assertSame($data['amount'], $invoice->fresh()->paid_amount);
    }

    /** @return array{int, int} */
    private function race(callable $first, callable $second): array
    {
        DB::purge();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            fread($sockets[1], 1);
            fclose($sockets[1]);
            exit($this->outcome($first));
        }
        fclose($sockets[1]);
        fwrite($sockets[0], '1');
        fclose($sockets[0]);
        $parent = $this->outcome($second);
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status));

        return [$parent, pcntl_wexitstatus($status)];
    }

    private function outcome(callable $action): int
    {
        try {
            $action();

            return 0;
        } catch (ValidationException) {
            return 2;
        } catch (\Throwable $exception) {
            fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);

            return 1;
        }
    }
}
