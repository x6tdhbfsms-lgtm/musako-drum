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
        Schema::create('trial_lesson_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_reference', 20)->unique();
            $table->char('access_token_hash', 64)->unique();
            $table->char('active_slot_key', 64)->nullable()->unique();
            $table->foreignId('lesson_slot_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_kana');
            $table->string('email');
            $table->string('email_normalized')->index();
            $table->string('phone', 30);
            $table->string('age_group', 30);
            $table->string('drum_experience', 50);
            $table->text('consultation')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('privacy_policy_version', 40);
            $table->timestamp('privacy_consented_at');
            $table->timestamp('requested_at');
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->index(['lesson_slot_id', 'status']);
            $table->index(['status', 'requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trial_lesson_requests');
    }
};
