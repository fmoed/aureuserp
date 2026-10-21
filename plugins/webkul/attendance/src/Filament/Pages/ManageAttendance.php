<?php

namespace Webkul\Attendance\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\Radio;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\Attendance\Settings\AttendanceSettings;

class ManageAttendance extends SettingsPage
{
    use HasPageShield;

    protected static ?string $cluster = Configurations::class;

    protected static string $settings = AttendanceSettings::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 1;

    /**
     * Shield generates page keys as page_{plugin}_{basename-snake}, so this
     * page owns `page_attendance_manage_attendance`.
     */
    protected static function getPagePermission(): ?string
    {
        return 'page_attendance_manage_attendance';
    }

    public static function getNavigationLabel(): string
    {
        return __('attendance::filament/pages/manage-attendance.navigation.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('attendance::filament/pages/manage-attendance.title');
    }

    /**
     * @return array<string, string>
     */
    public static function scopeOptions(): array
    {
        return collect(AttendanceSettings::options())
            ->mapWithKeys(fn (string $option): array => [
                $option => __('attendance::filament/pages/manage-attendance.form.employee-scope-options.'.$option),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function scopeDescriptions(): array
    {
        return collect(AttendanceSettings::options())
            ->mapWithKeys(fn (string $option): array => [
                $option => __('attendance::filament/pages/manage-attendance.form.employee-scope-descriptions.'.$option),
            ])
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('attendance::filament/pages/manage-attendance.sections.register-scope.title'))
                    ->description(__('attendance::filament/pages/manage-attendance.sections.register-scope.description'))
                    ->schema([
                        Radio::make('employee_scope')
                            ->label(__('attendance::filament/pages/manage-attendance.form.employee-scope.label'))
                            ->options(static::scopeOptions())
                            ->descriptions(static::scopeDescriptions())
                            ->default(AttendanceSettings::SCOPE_ALL)
                            ->required(),
                    ]),
            ]);
    }
}
