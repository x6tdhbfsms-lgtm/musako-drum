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
        Schema::create('trial_lesson_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trial_lesson_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('lesson_on');
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->index(['lesson_on', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trial_lesson_reminder_deliveries');
    }
};
