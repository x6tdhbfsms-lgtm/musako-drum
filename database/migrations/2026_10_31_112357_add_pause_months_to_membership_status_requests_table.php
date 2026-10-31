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
        Schema::table('membership_status_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('pause_months')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_status_requests', function (Blueprint $table) {
            $table->dropColumn('pause_months');
        });
    }
};
