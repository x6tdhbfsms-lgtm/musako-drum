<?php

namespace Tests\Feature;

use App\Actions\CancelMonthlyInvoice;
use App\Actions\ConfirmMonthlyInvoice;
use App\Actions\ReissueMonthlyInvoice;
use App\Enums\MonthlyInvoiceStatus;
use App\Models\LessonEnrollment;
use App\Models\MonthlyInvoice;
use App\Models\PriceRate;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\MonthlyInvoiceCancelledNotification;
use App\Notifications\MonthlyInvoiceConfirmedNotification;
use App\Services\MonthlyInvoiceGenerator;
use App\Services\MusakoNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvoiceReissueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function cancelledInvoice(): array
    {
        $this->travelTo('2026-09-14 12:00:00');
        $this->seed();
        $student = StudentProfile::factory()->create();
        LessonEnrollment::factory()->for($student)->create([
            'starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active',
            'monthly_lesson_limit' => 2, 'lesson_type' => 'regular', 'pricing_category' => 'standard',
        ]);
        $admin = User::factory()->admin()->create();
        $invoice = app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole();
        app(ConfirmMonthlyInvoice::class)->handle($invoice, $admin, true);
        app(CancelMonthlyInvoice::class)->handle($invoice, $admin, '料金訂正');

        return [$invoice->fresh(), $student, $admin];
    }

    public function test_reissue_preserves_original_and_creates_editable_draft_with_new_number_on_confirmation(): void
    {
        Notification::fake();
        [$old, $student, $admin] = $this->cancelledInvoice();
        $oldValues = $old->getRawOriginal();
        $oldItems = $old->items()->get()->toArray();
        $oldAudits = $old->audits()->count();
        PriceRate::factory()->create(['effective_from' => '2026-10-01', 'amount' => 12000]);

        $this->actingAs($admin)->post(route('staff.invoices.reissue', $old), ['reason' => '金額を再確認'])
            ->assertRedirect();
        $draft = $old->reissues()->sole();
        $this->assertSame(MonthlyInvoiceStatus::Draft, $draft->status);
        $this->assertNull($draft->invoice_number);
        $this->assertSame($admin->id, $draft->reissued_by_user_id);
        $this->assertNotNull($draft->reissued_at);
        $this->assertSame('金額を再確認', $draft->reissue_reason);
        $this->assertSame(12000, $draft->total_amount);

        $this->post(route('staff.invoices.adjustments.store', $draft), [
            'amount' => -1000, 'description' => '料金訂正', 'reason' => '確認済み',
        ])->assertSessionHasNoErrors();
        $this->post(route('staff.invoices.confirm', $draft), ['acknowledge_warnings' => 1])->assertSessionHasNoErrors();
        $new = $draft->fresh();
        $this->assertSame(11000, $new->total_amount);
        $this->assertNotSame($old->invoice_number, $new->invoice_number);
        $this->assertSame(MonthlyInvoiceStatus::Confirmed, $new->status);
        $this->assertSame($oldValues, $old->fresh()->getRawOriginal());
        $this->assertSame($oldItems, $old->items()->get()->toArray());
        $this->assertSame($oldAudits, $old->audits()->count());
        $this->assertSame(1, MonthlyInvoice::where('student_profile_id', $student->id)->where('status', '!=', 'cancelled')->count());
        app(MusakoNotificationService::class)->monthlyInvoiceConfirmed($new);
        app(MusakoNotificationService::class)->monthlyInvoiceCancelled($old);
        Notification::assertSentToTimes($student->user, MonthlyInvoiceConfirmedNotification::class, 2);
        Notification::assertSentToTimes($student->user, MonthlyInvoiceCancelledNotification::class, 1);

        $this->actingAs($student->user)->get(route('student.invoices.index'))
            ->assertOk()->assertSee('取消済み')->assertSee($old->invoice_number)->assertSee($new->invoice_number);
        $this->get(route('student.invoices.show', $old))->assertOk()->assertSee('お支払いは不要');
        $this->get(route('student.invoices.show', $new))->assertOk()->assertSee('再発行された有効な請求');
    }

    public function test_repeated_reissue_returns_same_draft_and_generation_uses_active_invoice(): void
    {
        [$old, $student, $admin] = $this->cancelledInvoice();
        $action = app(ReissueMonthlyInvoice::class);
        $first = $action->handle($old, $admin, '訂正');
        $second = $action->handle($old, $admin, '再送信');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, MonthlyInvoice::where('student_profile_id', $student->id)->count());
        $this->assertSame($first->id, app(MonthlyInvoiceGenerator::class)->generate('2026-10', $admin)->sole()->id);
        $this->assertSame(1, $first->audits()->where('event', 'reissued')->count());
    }

    public function test_draft_is_private_and_reissue_requires_admin_and_reason(): void
    {
        [$old, $student, $admin] = $this->cancelledInvoice();
        $this->actingAs($student->user)->post(route('staff.invoices.reissue', $old), ['reason' => '訂正'])->assertForbidden();
        $this->actingAs(User::factory()->teacher()->create())->post(route('staff.invoices.reissue', $old), ['reason' => '訂正'])->assertForbidden();
        $this->actingAs($admin)->post(route('staff.invoices.reissue', $old), ['reason' => ''])->assertSessionHasErrors('reason');
        $draft = app(ReissueMonthlyInvoice::class)->handle($old, $admin, '訂正');
        $this->actingAs($student->user)->get(route('student.invoices.show', $draft))->assertForbidden();
        $other = StudentProfile::factory()->create();
        $this->actingAs($other->user)->get(route('student.invoices.show', $old))->assertForbidden();
    }

    public function test_database_rejects_a_second_active_invoice_for_the_same_month(): void
    {
        [$old, $student, $admin] = $this->cancelledInvoice();
        app(ReissueMonthlyInvoice::class)->handle($old, $admin, '訂正');
        $this->expectException(QueryException::class);
        MonthlyInvoice::create([
            'student_profile_id' => $student->id, 'billing_month' => '2026-10-01',
            'status' => 'confirmed', 'generated_at' => now(),
        ]);
    }

    public function test_reissue_can_repeat_after_another_cancellation_without_rewriting_history(): void
    {
        [$old, $student, $admin] = $this->cancelledInvoice();
        $second = app(ReissueMonthlyInvoice::class)->handle($old, $admin, '訂正1');
        app(ConfirmMonthlyInvoice::class)->handle($second, $admin, true);
        app(CancelMonthlyInvoice::class)->handle($second, $admin, '再確認');
        $third = app(ReissueMonthlyInvoice::class)->handle($second, $admin, '訂正2');
        $this->assertSame($second->id, $third->reissued_from_invoice_id);
        $this->assertSame(3, MonthlyInvoice::where('student_profile_id', $student->id)->count());
        $this->assertSame(2, MonthlyInvoice::where('student_profile_id', $student->id)->where('status', 'cancelled')->count());
    }

    public function test_confirmed_notification_is_suppressed_if_cancelled_before_worker_runs(): void
    {
        [$old, $student] = $this->cancelledInvoice();
        $this->assertFalse((new MonthlyInvoiceConfirmedNotification($old))->shouldSend($student->user, 'mail'));
    }
}
