<?php

namespace Webkul\BiometricAttendance;

use Filament\Panel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;
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
use Webkul\BiometricAttendance\Services\PhpZktecoClient;
use Webkul\BiometricAttendance\Services\SyncPipeline;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\Support\Models\Scopes\CompanyScope;

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
                '2026_11_04_000005_add_punch_direction_mode_to_biometric_devices_table',
            ])
            ->hasDependencies([
                'employees',
                'attendance',
            ])
            ->runsMigrations()
            ->hasCommands([
                TestConnectionCommand::class,
                ReadAttendanceCommand::class,
                SyncPunchesCommand::class,
                SyncAllCommand::class,
                PushEventsCommand::class,
            ])
            ->hasSettings([
                '2026_10_12_000001_create_biometric_sync_settings',
            ])
            ->runsSettings()
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
            WriterRegistry::register('biometric-attendance', fn (?int $id): ?string => $id !== null ? Device::withoutGlobalScope(CompanyScope::class)->find($id)?->name : null);
        }

        // A barcode added (or corrected) in HR must recover the punches the
        // devices already delivered, without waiting for a device that may
        // be offline or unreachable.
        Employee::saved(function (Employee $employee): void {
            if (! filled($employee->barcode)) {
                return;
            }

            if (! $employee->wasRecentlyCreated && ! $employee->wasChanged(['barcode', 'company_id'])) {
                return;
            }

            try {
                $this->app->make(SyncPipeline::class)->syncEmployeeRetroactively($employee);
            } catch (Throwable $e) {
                Log::warning('Retroactive biometric backfill failed.', [
                    'employee_id' => $employee->getKey(),
                    'error'       => $e->getMessage(),
                ]);
            }
        });

        /**
         * One chained task: sync devices → push punches → pair rows. It
         * ticks on a fixed fifteen-minute grid, skips itself per tick unless
         * the stored cadence (config `biometric-attendance.sync_cadence`,
         * hourly default; the sync settings page binds to its options) is
         * due — so cadence changes apply on the next tick with no reboot,
         * and nothing reads settings at boot time. `--chain` keeps the three
         * steps in one process and `withoutOverlapping` plus the command's
         * own cache lock prevent duplicate runs. Ops note: production cron
         * needs `* * * * * php artisan schedule:run` (single entry; the
         * scheduler fans out). The attendance pairing step is skipped at
         * runtime when that plugin is absent, so boot and the whole schedule
         * stay working without it.
         */
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('biometric:sync-all --chain')
                ->everyFifteenMinutes()
                ->withoutOverlapping(30)
                ->skip(fn (): bool => ! SyncSchedule::isDue());
        });
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(BiometricAttendancePlugin::make());
        });
    }
}
