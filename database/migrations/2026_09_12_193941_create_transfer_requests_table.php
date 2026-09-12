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
        Schema::create('transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_reservation_request_id')->constrained('reservation_requests')->restrictOnDelete();
            $table->foreignId('requested_lesson_slot_id')->constrained('lesson_slots')->restrictOnDelete();
            $table->foreignId('resulting_reservation_request_id')->nullable()->constrained('reservation_requests')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();
            $table->text('student_note')->nullable();
            $table->text('staff_note')->nullable();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'status']);
            $table->index(['original_reservation_request_id', 'status']);
            $table->index(['requested_lesson_slot_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_requests');
    }
};
