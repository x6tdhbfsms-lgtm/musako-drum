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
        Schema::table('lesson_enrollments', function (Blueprint $table) {
            $table->foreignId('teacher_profile_id')->nullable()->after('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->after('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday')->nullable()->after('venue_id');
            $table->time('starts_at_time')->nullable()->after('weekday');
            $table->string('payment_method', 30)->nullable()->after('lesson_minutes');
            $table->foreignId('supersedes_lesson_enrollment_id')->nullable()->after('ends_on')->constrained('lesson_enrollments')->nullOnDelete();

            $table->index(['student_profile_id', 'starts_on', 'ends_on'], 'enrollments_student_period_index');
            $table->index(['teacher_profile_id', 'starts_on', 'ends_on'], 'enrollments_teacher_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lesson_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_lesson_enrollment_id');
            $table->dropConstrainedForeignId('venue_id');
            $table->dropConstrainedForeignId('teacher_profile_id');
            $table->dropIndex('enrollments_student_period_index');
            $table->dropIndex('enrollments_teacher_period_index');
            $table->dropColumn('payment_method');
            $table->dropColumn('starts_at_time');
            $table->dropColumn('weekday');
        });
    }
};
