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
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->date('lesson_entitlement_month')->nullable()->after('lesson_enrollment_id');
            $table->timestamp('completed_at')->nullable()->after('cancelled_at');
            $table->timestamp('monthly_limit_overridden_at')->nullable()->after('completed_at');
            $table->foreignId('monthly_limit_overridden_by_user_id')->nullable()->after('monthly_limit_overridden_at')->constrained('users')->nullOnDelete();
            $table->string('monthly_limit_override_reason')->nullable()->after('monthly_limit_overridden_by_user_id');

            $table->index(
                ['student_profile_id', 'lesson_entitlement_month', 'status'],
                'reservations_student_entitlement_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservation_requests', function (Blueprint $table) {
            $table->dropIndex('reservations_student_entitlement_status_index');
            $table->dropConstrainedForeignId('monthly_limit_overridden_by_user_id');
            $table->dropColumn([
                'lesson_entitlement_month',
                'completed_at',
                'monthly_limit_overridden_at',
                'monthly_limit_override_reason',
            ]);
        });
    }
};
