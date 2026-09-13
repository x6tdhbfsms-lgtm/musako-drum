<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_records', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('monthly_invoice_id');
            $table->unique(['monthly_invoice_id', 'idempotency_key'], 'payment_invoice_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_records', function (Blueprint $table): void {
            $table->dropUnique('payment_invoice_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
