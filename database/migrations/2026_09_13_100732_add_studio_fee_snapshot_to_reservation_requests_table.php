<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->unsignedInteger('studio_fee_amount')->nullable()->after('lesson_entitlement_month');
            $table->date('studio_fee_priced_on')->nullable()->after('studio_fee_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->dropColumn(['studio_fee_amount', 'studio_fee_priced_on']);
        });
    }
};
