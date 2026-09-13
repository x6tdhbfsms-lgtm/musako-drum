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
        Schema::create('admission_applications', function (Blueprint $table) {
            $table->id();
            $table->string('public_reference', 20)->unique();
            $table->char('access_token_hash', 64)->unique();
            $table->char('pending_email_key', 64)->nullable()->unique();
            $table->foreignId('trial_lesson_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_kana');
            $table->string('email');
            $table->string('email_normalized')->index();
            $table->string('phone', 30);
            $table->string('postal_code', 12)->nullable();
            $table->string('address');
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('lesson_type', 20);
            $table->string('pricing_category', 20);
            $table->unsignedSmallInteger('monthly_lesson_count');
            $table->unsignedSmallInteger('lesson_minutes');
            $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('preferred_start_date');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('starts_at_time')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('privacy_policy_version', 40);
            $table->timestamp('privacy_consented_at');
            $table->timestamp('requested_at');
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_student_profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->foreignId('converted_lesson_enrollment_id')->nullable()->constrained('lesson_enrollments')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admission_applications');
    }
};
