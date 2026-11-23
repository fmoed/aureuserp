<?php

namespace Webkul\RemoteAttendance;

use Filament\Panel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\RemoteAttendance\Console\SendRemoteRemindersCommand;
use Webkul\RemoteAttendance\Services\RemoteCheckInService;

class RemoteAttendanceServiceProvider extends PackageServiceProvider
{
    public static string $name = 'remote-attendance';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasTranslations()
            ->hasViews()
            ->hasDependencies([
                'employees',
                'attendance',
            ])
            ->runsMigrations()
            ->hasCommands([
                SendRemoteRemindersCommand::class,
            ])
            ->hasSettings([
                '2026_11_23_000001_create_remote_attendance_settings',
                '2026_11_23_000002_add_flexible_locations_to_remote_attendance_settings',
            ])
            ->runsSettings()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsMigrations();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('remote-attendance');
    }

    public function packageBooted(): void
    {
        if (class_exists(WriterRegistry::class)) {
            WriterRegistry::register(
                'remote',
                fn (?int $id = null): string => __('remote-attendance::app.source'),
                fn (Builder $query): Builder => RemoteCheckInService::applyEligibilityScope($query),
            );
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('remote-attendance:send-reminders')
                ->everyFifteenMinutes()
                ->withoutOverlapping(10);
        });
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(RemoteAttendancePlugin::make());
        });
    }
}
