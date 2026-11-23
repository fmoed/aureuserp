<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('remote_attendance.allowed_location_types')) {
            $this->migrator->add('remote_attendance.allowed_location_types', ['home']);
        }

        if (! $this->migrator->exists('remote_attendance.allowed_work_location_ids')) {
            $this->migrator->add('remote_attendance.allowed_work_location_ids', []);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('remote_attendance.allowed_location_types');
        $this->migrator->deleteIfExists('remote_attendance.allowed_work_location_ids');
    }
};
