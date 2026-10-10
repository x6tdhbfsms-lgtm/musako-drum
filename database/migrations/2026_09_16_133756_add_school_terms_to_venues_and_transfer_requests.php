<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_status_requests', function (Blueprint $table) {
            $table->json('released_schedule_snapshot')->nullable();
            $table->timestamp('schedule_released_at')->nullable();
        });
        Schema::table('venues', function (Blueprint $table) {
            $table->string('lesson_room_type', 20)->nullable();
        });
        Schema::table('trial_lesson_requests', function (Blueprint $table) {
            $table->unsignedInteger('cancellation_fee_amount')->nullable();
        });
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->json('terms_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trial_lesson_requests', fn (Blueprint $table) => $table->dropColumn('cancellation_fee_amount'));
        Schema::table('transfer_requests', fn (Blueprint $table) => $table->dropColumn('terms_snapshot'));
        Schema::table('membership_status_requests', fn (Blueprint $table) => $table->dropColumn(['released_schedule_snapshot', 'schedule_released_at']));
        Schema::table('venues', fn (Blueprint $table) => $table->dropColumn('lesson_room_type'));
    }
};
