<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_enrollments', function (Blueprint $table) {
            $table->json('regular_week_numbers')->nullable()->after('starts_at_time');
        });

        Schema::create('regular_schedule_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_enrollment_id')->constrained()->restrictOnDelete();
            $table->date('entitlement_month');
            $table->unsignedTinyInteger('expected_count');
            $table->unsignedTinyInteger('generated_count')->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->text('warning')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['lesson_enrollment_id', 'entitlement_month'], 'regular_batches_enrollment_month_unique');
        });

        Schema::create('regular_schedule_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regular_schedule_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('source_key', 80);
            $table->unsignedTinyInteger('week_number');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->date('lesson_entitlement_month');
            $table->string('status', 20)->default('draft')->index();
            $table->json('conflict_reasons')->nullable();
            $table->boolean('manually_adjusted')->default(false);
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['regular_schedule_batch_id', 'source_key'], 'regular_occurrences_batch_source_unique');
            $table->index(['teacher_profile_id', 'starts_at']);
            $table->index(['student_profile_id', 'starts_at']);
            $table->index(['venue_id', 'starts_at']);
        });

        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->foreignId('regular_schedule_occurrence_id')->nullable()->after('lesson_enrollment_id')
                ->unique()->constrained('regular_schedule_occurrences')->nullOnDelete();
        });

        Schema::create('regular_schedule_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regular_schedule_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('regular_schedule_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamp('created_at');
            $table->index(['regular_schedule_batch_id', 'created_at'], 'regular_audits_batch_created_index');
        });

        Schema::create('regular_schedule_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('automatic_generation_enabled')->default(true);
            $table->unsignedTinyInteger('generation_day')->default(20);
            $table->timestamps();
        });

        Schema::create('regular_schedule_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_profile_id');
            $table->date('entitlement_month');
            $table->string('schedule_signature', 64);
            $table->timestamp('notified_at');
            $table->timestamps();
            $table->unique(['student_profile_id', 'entitlement_month', 'schedule_signature'], 'regular_notifications_dedupe_unique');
            $table->foreign('student_profile_id', 'regular_sched_notify_student_fk')->references('id')->on('student_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regular_schedule_notification_deliveries');
        Schema::dropIfExists('regular_schedule_settings');
        Schema::dropIfExists('regular_schedule_audits');
        Schema::table('reservation_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('regular_schedule_occurrence_id'));
        Schema::dropIfExists('regular_schedule_occurrences');
        Schema::dropIfExists('regular_schedule_batches');
        Schema::table('lesson_enrollments', fn (Blueprint $table) => $table->dropColumn('regular_week_numbers'));
    }
};
