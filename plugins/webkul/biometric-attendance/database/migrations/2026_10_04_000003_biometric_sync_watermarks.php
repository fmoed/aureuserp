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
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('last_sync_uid')->nullable()->comment('Max Device Uid Stored');
        });

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable()->comment('Pushed To Attendance Events');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->dropColumn('pushed_at');
        });

        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn('last_sync_uid');
        });
    }
};
