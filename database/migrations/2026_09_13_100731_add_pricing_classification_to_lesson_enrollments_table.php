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
            $table->string('lesson_type', 20)->default('regular')->after('course_id');
            $table->string('pricing_category', 20)->default('standard')->after('lesson_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lesson_enrollments', function (Blueprint $table) {
            $table->dropColumn(['lesson_type', 'pricing_category']);
        });
    }
};
