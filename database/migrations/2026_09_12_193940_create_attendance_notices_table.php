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
        Schema::create('attendance_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->dateTime('expected_arrival_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['type', 'submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_notices');
    }
};
