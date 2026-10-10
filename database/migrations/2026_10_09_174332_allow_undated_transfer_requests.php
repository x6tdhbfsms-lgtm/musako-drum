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
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_lesson_slot_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('transfer_requests')->whereNull('requested_lesson_slot_id')->exists()) {
            throw new RuntimeException('日程未定の振替履歴があるため、この変更は戻せません。');
        }
        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_lesson_slot_id')->nullable(false)->change();
        });
    }
};
