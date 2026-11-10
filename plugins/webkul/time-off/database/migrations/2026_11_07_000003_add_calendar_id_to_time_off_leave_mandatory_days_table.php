<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('time_off_leave_mandatory_days') && ! Schema::hasColumn('time_off_leave_mandatory_days', 'calendar_id')) {
            Schema::table('time_off_leave_mandatory_days', function (Blueprint $table): void {
                $table->unsignedBigInteger('calendar_id')->nullable()->after('company_id');
                $table->foreign('calendar_id')->references('id')->on('calendars')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('time_off_leave_mandatory_days') && Schema::hasColumn('time_off_leave_mandatory_days', 'calendar_id')) {
            Schema::table('time_off_leave_mandatory_days', function (Blueprint $table): void {
                $table->dropForeign(['calendar_id']);
                $table->dropColumn('calendar_id');
            });
        }
    }
};
