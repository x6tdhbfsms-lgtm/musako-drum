<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('lesson_type', 30)->default('individual');
            $table->unsignedSmallInteger('default_lesson_minutes')->default(60);
            $table->unsignedSmallInteger('default_monthly_lessons')->nullable();
            $table->unsignedSmallInteger('default_capacity')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone')->default('Asia/Tokyo');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
        Schema::dropIfExists('courses');
    }
};
