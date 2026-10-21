<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrator->add('attendance.employee_scope', 'all');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->migrator->deleteIfExists('attendance.employee_scope');
    }
};
