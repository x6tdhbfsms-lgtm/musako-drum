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
        Schema::create('contract_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->date('effective_on');
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->text('student_note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
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
        Schema::dropIfExists('contract_change_requests');
    }
};
