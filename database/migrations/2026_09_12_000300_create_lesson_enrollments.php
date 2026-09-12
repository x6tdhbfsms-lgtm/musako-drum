<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('monthly_lesson_limit')->nullable();
            $table->unsignedSmallInteger('lesson_minutes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['student_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_enrollments');
    }
};
