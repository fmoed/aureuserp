<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrator->add('biometric_sync.sync_cadence', 'hourly');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->migrator->deleteIfExists('biometric_sync.sync_cadence');
    }
};
