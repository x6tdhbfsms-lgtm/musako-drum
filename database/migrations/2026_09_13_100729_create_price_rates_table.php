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
        Schema::create('price_rates', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->string('pricing_category', 20)->nullable();
            $table->unsignedSmallInteger('monthly_lesson_count')->nullable();
            $table->unsignedInteger('amount');
            $table->date('effective_from');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'pricing_category', 'monthly_lesson_count', 'effective_from'], 'price_rates_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_rates');
    }
};
