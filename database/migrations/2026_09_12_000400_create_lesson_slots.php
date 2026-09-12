<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('status', 20)->default('open')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['teacher_profile_id', 'starts_at']);
            $table->index(['venue_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_slots');
    }
};
