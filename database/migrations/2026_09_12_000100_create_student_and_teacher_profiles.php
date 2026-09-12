<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('student_number')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('address')->nullable();
            $table->date('joined_on')->nullable();
            $table->timestamps();
        });
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->text('bio')->nullable();
            $table->boolean('is_accepting_bookings')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
        Schema::dropIfExists('student_profiles');
    }
};
