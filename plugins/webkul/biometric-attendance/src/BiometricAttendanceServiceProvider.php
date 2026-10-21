<?php

namespace Webkul\BiometricAttendance;

use Filament\Panel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\BiometricAttendance\Console\PushEventsCommand;
use Webkul\BiometricAttendance\Console\ReadAttendanceCommand;
use Webkul\BiometricAttendance\Console\SyncAllCommand;
use Webkul\BiometricAttendance\Console\SyncPunchesCommand;
use Webkul\BiometricAttendance\Console\TestConnectionCommand;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Policies\DevicePolicy;
use Webkul\BiometricAttendance\Scheduling\SyncSchedule;
use Webkul\BiometricAttendance\Services\AttendanceBridge;
use Webkul\BiometricAttendance\Services\PhpZktecoClient;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class BiometricAttendanceServiceProvider extends PackageServiceProvider
{
    public static string $name = 'biometric-attendance';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasTranslations()
            ->hasMigrations([
                '2026_09_20_000001_create_biometric_devices_table',
                '2026_09_23_000002_create_biometric_punches_table',
                '2026_10_04_000003_biometric_sync_watermarks',
                '2026_10_05_000004_use_natural_key_on_biometric_punches_table',
            ])
            ->hasDependencies([
                'employees',
            ])
            ->runsMigrations()
            ->hasCommands([
                TestConnectionCommand::class,
                ReadAttendanceCommand::class,
                SyncPunchesCommand::class,
                SyncAllCommand::class,
                PushEventsCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsMigrations();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('biometric-attendance');
    }

    public function packageBooted(): void
    {
        $this->app->bind(ZktecoClientInterface::class, PhpZktecoClient::class);

        Gate::policy(Device::class, DevicePolicy::class);

        if (class_exists(WriterRegistry::class)) {
            WriterRegistry::register('biometric-attendance', fn (?int $id): ?string => Device::find($id)?->name);
        }

        /**
         * Chain schedule: sync devices → push punches → pair rows. Cadence comes
         * from SyncSchedule (config `biometric-attendance.sync_cadence`, hourly
         * default; the future settings page binds to its options). Ops note:
         * production cron needs `* * * * * php artisan schedule:run` (single
         * entry; the scheduler fans out). The attendance pairing step is skipped
         * at runtime when that plugin is absent, so boot and the whole schedule
         * stay working without it.
         */
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            SyncSchedule::apply($schedule->command('biometric:sync-all'));
            SyncSchedule::apply($schedule->command('biometric:push-events'));

            SyncSchedule::apply($schedule->call(function (): void {
                if (AttendanceBridge::isAvailable()) {
                    Artisan::call('attendance:process-events');
                }
            })->name('attendance:process-events'));
        });
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(BiometricAttendancePlugin::make());
        });
    }
}
