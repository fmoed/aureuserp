<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicates = DB::table('biometric_punches')
            ->select('device_id', 'zk_user_id', 'punch_time', DB::raw('MIN(id) as keep_id'))
            ->groupBy('device_id', 'zk_user_id', 'punch_time')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('biometric_punches')
                ->where('device_id', $duplicate->device_id)
                ->where('zk_user_id', $duplicate->zk_user_id)
                ->when(
                    $duplicate->punch_time === null,
                    fn ($query) => $query->whereNull('punch_time'),
                    fn ($query) => $query->where('punch_time', $duplicate->punch_time)
                )
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->unique(['device_id', 'zk_user_id', 'punch_time'], 'biometric_punches_device_user_time_unique');
            $table->index(['device_id', 'device_uid'], 'biometric_punches_device_uid_index');
        });

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'device_uid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->unique(['device_id', 'device_uid']);
        });

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->dropUnique('biometric_punches_device_user_time_unique');
            $table->dropIndex('biometric_punches_device_uid_index');
        });
    }
};
