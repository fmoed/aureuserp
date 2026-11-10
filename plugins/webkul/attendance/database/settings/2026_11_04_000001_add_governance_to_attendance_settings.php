<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('attendance.count_pending_leave_as_absent')) {
            $this->migrator->add('attendance.count_pending_leave_as_absent', true);
        }

        if (! $this->migrator->exists('attendance.duplicate_punch_window_seconds')) {
            $this->migrator->add('attendance.duplicate_punch_window_seconds', 120);
        }

        if (! $this->migrator->exists('attendance.max_shift_hours')) {
            $this->migrator->add('attendance.max_shift_hours', 16);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('attendance.count_pending_leave_as_absent');
        $this->migrator->deleteIfExists('attendance.duplicate_punch_window_seconds');
        $this->migrator->deleteIfExists('attendance.max_shift_hours');
    }
};
