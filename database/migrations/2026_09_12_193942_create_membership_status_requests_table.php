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
        Schema::create('membership_status_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->date('effective_on');
            $table->text('reason')->nullable();
            $table->text('student_note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('staff_note')->nullable();
            $table->json('previous_enrollment_statuses')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['student_profile_id', 'status']);
            $table->index(['status', 'effective_on']);
            $table->index(['type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_status_requests');
    }
};
