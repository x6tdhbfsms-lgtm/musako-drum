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
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->string('display_mode', 30)->default('current_prices');
            $table->unsignedInteger('normal_admission_fee')->nullable();
            $table->boolean('admission_campaign_enabled')->default(false);
            $table->unsignedInteger('admission_campaign_fee')->nullable();
            $table->string('admission_campaign_message')->nullable();
            $table->text('pricing_notice')->nullable();
            $table->string('lesson_pricing_url', 2048)->nullable();
            $table->string('studio_pricing_url', 2048)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};
