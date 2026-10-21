<?php

namespace Webkul\BiometricAttendance\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Cron\CronExpression;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Throwable;
use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\BiometricAttendance\Filament\Actions\SyncAllNowAction;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Scheduling\SyncSchedule;
use Webkul\BiometricAttendance\Settings\BiometricSyncSettings;

class ManageSync extends SettingsPage
{
    use HasPageShield;

    protected static ?string $cluster = Configurations::class;

    protected static string $settings = BiometricSyncSettings::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    /**
     * Shield generates page keys as page_{plugin}_{basename-snake}, so this
     * page owns `page_biometric_attendance_manage_sync` (verified in the
     * permissions table after shield:generate, not assumed).
     */
    protected static function getPagePermission(): ?string
    {
        return 'page_biometric_attendance_manage_sync';
    }

    public static function getNavigationLabel(): string
    {
        return __('biometric-attendance::filament/pages/manage-sync.navigation.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('biometric-attendance::filament/pages/manage-sync.title');
    }

    /**
     * @return array<string, string>
     */
    public static function cadenceOptions(): array
    {
        return collect(SyncSchedule::options())
            ->mapWithKeys(fn (string $option): array => [
                $option => __('biometric-attendance::filament/pages/manage-sync.form.cadence-options.'.$option),
            ])
            ->all();
    }

    public static function nextRunDescription(?string $cadence = null): string
    {
        $timezone = config('app.timezone');

        try {
            $next = CronExpression::factory(SyncSchedule::cronExpression($cadence))
                ->getNextRunDate('now', 0, false, $timezone);
        } catch (Throwable) {
            return __('biometric-attendance::filament/pages/manage-sync.info.next-run.unknown');
        }

        return Carbon::instance($next)->locale(app()->getLocale())->isoFormat('dddd D MMMM YYYY, HH:mm').' ('.$timezone.')';
    }

    public static function lastSyncDescription(): string
    {
        $last = Device::query()->max('last_sync_at');

        if (! $last) {
            return __('biometric-attendance::filament/pages/manage-sync.info.last-sync.never');
        }

        return Carbon::parse($last)->locale(app()->getLocale())->isoFormat('dddd D MMMM YYYY, HH:mm');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('biometric-attendance::filament/pages/manage-sync.sections.interval'))
                    ->schema([
                        Select::make('sync_cadence')
                            ->label(__('biometric-attendance::filament/pages/manage-sync.form.cadence.label'))
                            ->helperText(__('biometric-attendance::filament/pages/manage-sync.form.cadence.hint'))
                            ->options(static::cadenceOptions())
                            ->required()
                            ->extraAttributes(['style' => 'max-width: 32rem;']),
                    ]),
                Section::make(__('biometric-attendance::filament/pages/manage-sync.sections.status'))
                    ->description(__('biometric-attendance::filament/pages/manage-sync.info.ops-note'))
                    ->columns([
                        'default' => 1,
                        'sm'      => 2,
                    ])
                    ->schema([
                        Placeholder::make('next_run_info')
                            ->label(__('biometric-attendance::filament/pages/manage-sync.info.next-run.label'))
                            ->content(fn (): string => static::nextRunDescription()),
                        Placeholder::make('last_sync_info')
                            ->label(__('biometric-attendance::filament/pages/manage-sync.info.last-sync.label'))
                            ->content(fn (): string => static::lastSyncDescription()),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            SyncAllNowAction::make(),
        ];
    }
}
