<?php

namespace Webkul\BiometricAttendance;

use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Webkul\BiometricAttendance\Console\ReadAttendanceCommand;
use Webkul\BiometricAttendance\Console\SyncPunchesCommand;
use Webkul\BiometricAttendance\Console\TestConnectionCommand;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Policies\DevicePolicy;
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
            ])
            ->hasDependencies([
                'employees',
            ])
            ->runsMigrations()
            ->hasCommands([
                TestConnectionCommand::class,
                ReadAttendanceCommand::class,
                SyncPunchesCommand::class,
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
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(BiometricAttendancePlugin::make());
        });
    }
}
