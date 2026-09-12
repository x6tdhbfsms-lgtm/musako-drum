<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('student_note')->nullable();
            $table->text('staff_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['student_profile_id', 'lesson_slot_id']);
            $table->index(['lesson_slot_id', 'status']);
            $table->index(['student_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_requests');
    }
};
