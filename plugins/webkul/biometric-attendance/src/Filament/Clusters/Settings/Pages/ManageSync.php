<?php

namespace Webkul\BiometricAttendance\Filament\Clusters\Settings\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Cron\CronExpression;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Throwable;
use Webkul\BiometricAttendance\Filament\Actions\SyncAllNowAction;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Scheduling\SyncSchedule;
use Webkul\BiometricAttendance\Settings\BiometricSyncSettings;
use Webkul\Support\Filament\Clusters\Settings;

class ManageSync extends SettingsPage
{
    use HasPageShield;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'biometric-attendance/manage-sync';

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

    /**
     * A plain label, not the NavigationGroup enum: that enum implements
     * HasIcon, and a sub-navigation group icon combined with item icons is
     * rejected by Filament's sidebar renderer.
     */
    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.navigation.attendance');
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

    public static function intervalHint(): HtmlString
    {
        $hint = e(__('biometric-attendance::filament/pages/manage-sync.form.cadence.hint'));

        if (! class_exists(DeviceResource::class) || ! Route::has(DeviceResource::getRouteBaseName().'.index')) {
            return new HtmlString($hint);
        }

        $link = '<a href="'.e(DeviceResource::getUrl('index')).'" class="font-semibold text-primary-600 hover:underline">'
            .e(__('biometric-attendance::filament/pages/manage-sync.form.cadence.devices-link')).'</a>';

        return new HtmlString($hint.' '.$link);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('biometric-attendance::filament/pages/manage-sync.sections.interval'))
                    ->schema([
                        Select::make('sync_cadence')
                            ->label(__('biometric-attendance::filament/pages/manage-sync.form.cadence.label'))
                            ->helperText(fn (): HtmlString => static::intervalHint())
                            ->options(static::cadenceOptions())
                            ->required(),
                    ]),
                Section::make(__('biometric-attendance::filament/pages/manage-sync.sections.status'))
                    ->description(__('biometric-attendance::filament/pages/manage-sync.info.ops-note'))
                    ->columns([
                        'default' => 1,
                        'sm'      => 2,
                    ])
                    ->schema([
                        TextEntry::make('next_run_info')
                            ->label(__('biometric-attendance::filament/pages/manage-sync.info.next-run.label'))
                            ->state(fn (): string => static::nextRunDescription()),
                        TextEntry::make('last_sync_info')
                            ->label(__('biometric-attendance::filament/pages/manage-sync.info.last-sync.label'))
                            ->state(fn (): string => static::lastSyncDescription()),
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
