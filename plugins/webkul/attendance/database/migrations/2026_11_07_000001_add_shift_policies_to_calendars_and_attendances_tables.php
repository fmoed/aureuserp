<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendars', function (Blueprint $table): void {
            if (! Schema::hasColumn('calendars', 'shift_type')) {
                $table->string('shift_type', 32)->default('fixed');
                $table->string('pairing_mode', 32)->default('first_in_last_out');
                $table->unsignedSmallInteger('check_in_window_before_minutes')->default(120);
                $table->unsignedSmallInteger('check_out_window_after_minutes')->default(240);
                $table->unsignedSmallInteger('late_grace_minutes')->default(15);
                $table->unsignedSmallInteger('early_leave_grace_minutes')->default(10);
                $table->string('early_arrival_policy', 32)->default('clamp_to_schedule');
                $table->unsignedSmallInteger('max_early_offset_minutes')->default(60);
                $table->string('break_policy', 32)->default('auto_deduct');
                $table->unsignedSmallInteger('break_duration_minutes')->default(60);
                $table->unsignedSmallInteger('break_trigger_minutes')->default(300);
                $table->string('missing_checkout_policy', 32)->nullable();
                $table->string('holiday_policy', 32)->default('excuse_all_applicable');
                $table->unsignedTinyInteger('half_day_threshold_percent')->default(50);
                $table->unsignedTinyInteger('absent_threshold_percent')->default(25);
                $table->boolean('overtime_enabled')->default(true);
                $table->unsignedSmallInteger('min_overtime_minutes')->default(30);
            }
        });

        Schema::table('attendance_attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_attendances', 'break_minutes')) {
                $table->unsignedSmallInteger('break_minutes')->default(0);
            }

            if (! Schema::hasColumn('attendance_attendances', 'is_estimated')) {
                $table->boolean('is_estimated')->default(false);
            }
        });

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

        Schema::table('attendance_attendances', function (Blueprint $table): void {
            $table->dropColumn(['break_minutes', 'is_estimated']);
        });

        Schema::table('calendars', function (Blueprint $table): void {
            $table->dropColumn([
                'shift_type',
                'pairing_mode',
                'check_in_window_before_minutes',
                'check_out_window_after_minutes',
                'late_grace_minutes',
                'early_leave_grace_minutes',
                'early_arrival_policy',
                'max_early_offset_minutes',
                'break_policy',
                'break_duration_minutes',
                'break_trigger_minutes',
                'missing_checkout_policy',
                'holiday_policy',
                'half_day_threshold_percent',
                'absent_threshold_percent',
                'overtime_enabled',
                'min_overtime_minutes',
            ]);
        });
    }
};
