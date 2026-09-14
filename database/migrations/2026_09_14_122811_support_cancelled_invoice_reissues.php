<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('monthly_invoices', function (Blueprint $table) {
            $table->foreignId('reissued_from_invoice_id')->nullable()->unique()->constrained('monthly_invoices')->restrictOnDelete();
            $table->timestamp('reissued_at')->nullable();
            $table->foreignId('reissued_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reissue_reason')->nullable();
            $table->unsignedTinyInteger('active_invoice_key')->nullable()->virtualAs("CASE WHEN status <> 'cancelled' THEN 1 ELSE NULL END");
            $table->unique(['student_profile_id', 'billing_month', 'active_invoice_key'], 'monthly_invoices_one_active');
        });
        Schema::table('monthly_invoices', fn (Blueprint $table) => $table->dropUnique(['student_profile_id', 'billing_month']));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('monthly_invoices')->whereNotNull('reissued_from_invoice_id')->exists()) {
            throw new RuntimeException('再発行履歴があるためrollbackできません。履歴を保持した前進修正が必要です。');
        }
        Schema::table('monthly_invoices', fn (Blueprint $table) => $table->unique(['student_profile_id', 'billing_month']));
        Schema::table('monthly_invoices', function (Blueprint $table) {
            $table->dropUnique('monthly_invoices_one_active');
            $table->dropConstrainedForeignId('reissued_from_invoice_id');
            $table->dropConstrainedForeignId('reissued_by_user_id');
            $table->dropColumn(['reissued_at', 'reissue_reason', 'active_invoice_key']);
        });
    }
};
