<?php

namespace Webkul\Attendance\Filament\Clusters\Settings\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Enums\HolidayPolicy;
use Webkul\Attendance\Enums\MandatoryDaysPolicy;
use Webkul\Attendance\Enums\RoundingMode;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\Support\Filament\Clusters\Settings;

class ManageAttendance extends SettingsPage
{
    use HasPageShield;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'attendance/manage-attendance';

    protected static string $settings = AttendanceSettings::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

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

    /**
     * Quick links to the shift and device registries, only when the target
     * route exists (the biometric plugin is optional).
     */
    public static function quickLinks(): HtmlString
    {
        $links = [];

        if (class_exists(CalendarResource::class) && Route::has(CalendarResource::getRouteBaseName().'.index')) {
            $links[] = '<a href="'.e(CalendarResource::getUrl('index')).'" class="font-semibold text-primary-600 hover:underline">'
                .e(__('attendance::filament/pages/manage-attendance.links.calendars')).'</a>';
        }

        if (class_exists(DeviceResource::class) && Route::has(DeviceResource::getRouteBaseName().'.index')) {
            $links[] = '<a href="'.e(DeviceResource::getUrl('index')).'" class="font-semibold text-primary-600 hover:underline">'
                .e(__('attendance::filament/pages/manage-attendance.links.devices')).'</a>';
        }

        return new HtmlString(implode(' · ', $links));
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
                            ->required()
                            ->helperText(fn (): HtmlString => static::quickLinks()),
                    ]),
                Section::make(__('attendance::filament/pages/manage-attendance.sections.governance.title'))
                    ->description(__('attendance::filament/pages/manage-attendance.sections.governance.description'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('count_pending_leave_as_absent')
                            ->label(__('attendance::filament/pages/manage-attendance.form.count-pending-leave.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.count-pending-leave.hint'))
                            ->default(true),
                        TextInput::make('duplicate_punch_window_seconds')
                            ->label(__('attendance::filament/pages/manage-attendance.form.duplicate-punch-window.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.duplicate-punch-window.hint'))
                            ->numeric()
                            ->minValue(0)
                            ->default(AttendanceSettings::DEFAULT_DUPLICATE_PUNCH_SECONDS)
                            ->suffix(__('attendance::filament/pages/manage-attendance.form.seconds-suffix')),
                        TextInput::make('max_shift_hours')
                            ->label(__('attendance::filament/pages/manage-attendance.form.max-shift-hours.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.max-shift-hours.hint'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(48)
                            ->default(AttendanceSettings::DEFAULT_MAX_SHIFT_HOURS)
                            ->suffix(__('attendance::filament/pages/manage-attendance.form.hours-suffix')),
                    ]),
                Section::make(__('attendance::filament/pages/manage-attendance.sections.hours-policies.title'))
                    ->description(__('attendance::filament/pages/manage-attendance.sections.hours-policies.description'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('half_day_threshold_percent')
                            ->label(__('attendance::filament/pages/manage-attendance.form.half-day-threshold.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.half-day-threshold.hint'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(50)
                            ->required()
                            ->suffix('%'),
                        Select::make('holiday_policy')
                            ->label(__('attendance::filament/pages/manage-attendance.form.holiday-policy.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.holiday-policy.hint'))
                            ->options(HolidayPolicy::options())
                            ->default(HolidayPolicy::ExcuseAllApplicable->value)
                            ->required()
                            ->native(false),
                        Select::make('early_arrival_policy')
                            ->label(__('attendance::filament/pages/manage-attendance.form.early-arrival-policy.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.early-arrival-policy.hint'))
                            ->options(EarlyArrivalPolicy::options())
                            ->default(EarlyArrivalPolicy::ClampToSchedule->value)
                            ->required()
                            ->live()
                            ->native(false),
                        TextInput::make('max_early_offset_minutes')
                            ->label(__('attendance::filament/pages/manage-attendance.form.max-early-offset.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.max-early-offset.hint'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(720)
                            ->default(60)
                            ->visible(fn (Get $get): bool => in_array(
                                $get('early_arrival_policy') ?? EarlyArrivalPolicy::ClampToSchedule->value,
                                [EarlyArrivalPolicy::OffsetEarlyDeparture->value, EarlyArrivalPolicy::CountAsOvertime->value],
                                true,
                            ))
                            ->suffix(__('attendance::filament/pages/manage-attendance.form.minutes-suffix')),
                        Select::make('mandatory_days_policy')
                            ->label(__('attendance::filament/pages/manage-attendance.form.mandatory-days-policy.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.mandatory-days-policy.hint'))
                            ->options(MandatoryDaysPolicy::options())
                            ->default(MandatoryDaysPolicy::TreatAsHoliday->value)
                            ->required()
                            ->native(false),
                        Select::make('rounding_mode')
                            ->label(__('attendance::filament/pages/manage-attendance.form.rounding-mode.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.rounding-mode.hint'))
                            ->options(RoundingMode::options())
                            ->default(RoundingMode::None->value)
                            ->required()
                            ->native(false),
                        Toggle::make('count_unpaid_leave_as_absent')
                            ->label(__('attendance::filament/pages/manage-attendance.form.count-unpaid-leave.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.count-unpaid-leave.hint'))
                            ->default(false),
                        DatePicker::make('attendance_lock_date')
                            ->label(__('attendance::filament/pages/manage-attendance.form.attendance-lock-date.label'))
                            ->helperText(__('attendance::filament/pages/manage-attendance.form.attendance-lock-date.hint'))
                            ->native(false)
                            ->maxDate(fn (): string => now()->toDateString())
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
