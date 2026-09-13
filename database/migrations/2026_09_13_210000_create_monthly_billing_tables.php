<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('singleton_key', 20)->default('default')->unique();
            $table->boolean('auto_generate_enabled')->default(true);
            $table->unsignedTinyInteger('generation_day')->default(25);
            $table->time('generation_time')->default('02:00');
            $table->string('due_rule', 30)->default('previous_month_end');
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->boolean('invoice_notifications_enabled')->default(true);
            $table->boolean('payment_notifications_enabled')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('monthly_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->date('billing_month');
            $table->string('invoice_number', 30)->nullable()->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->string('payment_status', 30)->default('unpaid')->index();
            $table->string('payment_method', 30)->nullable();
            $table->string('external_payment_provider', 80)->nullable();
            $table->string('external_payment_reference', 191)->nullable();
            $table->date('due_on')->nullable()->index();
            $table->json('pricing_snapshot')->nullable();
            $table->json('contract_snapshot')->nullable();
            $table->json('warnings')->nullable();
            $table->boolean('requires_review')->default(false)->index();
            $table->boolean('has_manual_adjustments')->default(false);
            $table->integer('base_lesson_fee')->nullable();
            $table->integer('flex_surcharge')->default(0);
            $table->integer('lesson_fee_total')->nullable();
            $table->integer('studio_fee_total')->default(0);
            $table->integer('subtotal')->default(0);
            $table->integer('adjustments_total')->default(0);
            $table->integer('total_amount')->default(0);
            $table->unsignedInteger('paid_amount')->default(0);
            $table->timestamp('generated_at');
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['student_profile_id', 'billing_month']);
            $table->index(['billing_month', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('description');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->integer('unit_amount');
            $table->integer('amount');
            $table->boolean('is_manual')->default(false);
            $table->nullableMorphs('source');
            $table->json('pricing_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['monthly_invoice_id', 'type']);
        });

        Schema::create('payment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->date('paid_on');
            $table->string('payment_method', 30);
            $table->string('external_payment_provider', 80)->nullable();
            $table->string('external_payment_reference', 191)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['external_payment_provider', 'external_payment_reference'], 'payment_external_reference_unique');
            $table->index(['monthly_invoice_id', 'paid_on']);
        });

        Schema::create('monthly_invoice_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 50);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['monthly_invoice_id', 'created_at']);
        });

        Schema::create('invoice_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('signature', 64);
            $table->timestamp('notified_at');
            $table->timestamps();
            $table->unique(['monthly_invoice_id', 'type', 'signature'], 'invoice_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_notification_deliveries');
        Schema::dropIfExists('monthly_invoice_audits');
        Schema::dropIfExists('payment_records');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('monthly_invoices');
        Schema::dropIfExists('billing_settings');
    }
};
