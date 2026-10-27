<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login_name', 64)->nullable()->unique();
            $table->string('email')->nullable()->change();
        });
        Schema::table('admission_applications', function (Blueprint $table) {
            $table->string('login_name', 64)->nullable();
            $table->string('pending_login_name', 64)->nullable()->unique();
            $table->string('initial_password_hash')->nullable();
            $table->string('email')->nullable()->change();
            $table->string('email_normalized')->nullable()->change();
        });
        DB::table('users')->where('role', 'student')->orderBy('id')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['login_name' => 'student-'.$user->id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('ログイン情報の消失を防ぐため、この移行は自動で戻せません。');
    }
};
