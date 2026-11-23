<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrator->add('remote_attendance.reminder_enabled', true);
        $this->migrator->add('remote_attendance.reminder_time', '09:00');
        $this->migrator->add('remote_attendance.require_checkout', false);
        $this->migrator->add('remote_attendance.only_home_location', true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->migrator->deleteIfExists('remote_attendance.reminder_enabled');
        $this->migrator->deleteIfExists('remote_attendance.reminder_time');
        $this->migrator->deleteIfExists('remote_attendance.require_checkout');
        $this->migrator->deleteIfExists('remote_attendance.only_home_location');
    }
};
