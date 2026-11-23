<?php

namespace Webkul\RemoteAttendance\Filament\Clusters\Settings\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Employee\Enums\WorkLocation as WorkLocationEnum;
use Webkul\Employee\Models\WorkLocation;
use Webkul\RemoteAttendance\Notifications\RemoteCheckInReminderNotification;
use Webkul\RemoteAttendance\Settings\RemoteAttendanceSettings;
use Webkul\Support\Filament\Clusters\Settings;
use Webkul\Support\Models\Scopes\CompanyScope;

class ManageRemoteAttendance extends SettingsPage
{
    use HasPageShield;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'remote-attendance/manage-remote-attendance';

    protected static string $settings = RemoteAttendanceSettings::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-computer-desktop';

    protected static function getPagePermission(): ?string
    {
        return 'page_remote_attendance_manage_remote_attendance';
    }

    public static function getNavigationLabel(): string
    {
        return __('remote-attendance::filament/pages/manage-remote-attendance.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.navigation.attendance');
    }

    public function getTitle(): string|Htmlable
    {
        return __('remote-attendance::filament/pages/manage-remote-attendance.title');
    }

    /**
     * @return array<int, string>
     */
    public static function workLocationOptions(): array
    {
        return once(fn (): array => WorkLocation::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereNull('is_active'))
            ->orderBy('name')
            ->get(['id', 'name', 'location_type'])
            ->mapWithKeys(fn (WorkLocation $location): array => [
                $location->getKey() => $location->location_type
                    ? "{$location->name} ({$location->location_type->getLabel()})"
                    : $location->name,
            ])
            ->all());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTestReminder')
                ->label(__('remote-attendance::filament/pages/manage-remote-attendance.actions.test-reminder.label'))
                ->icon('heroicon-o-bell-alert')
                ->color('info')
                ->action(function (): void {
                    $user = Auth::user();

                    if (! $user) {
                        return;
                    }

                    if (blank($user->email)) {
                        Notification::make()
                            ->warning()
                            ->title(__('remote-attendance::filament/pages/manage-remote-attendance.actions.test-reminder.failed'))
                            ->body(__('remote-attendance::filament/pages/manage-remote-attendance.actions.test-reminder.no-email'))
                            ->send();

                        return;
                    }

                    $today = Carbon::today()->toDateString();

                    $company = $user->employee()
                        ->withoutGlobalScope(CompanyScope::class)
                        ->with('company.partner')
                        ->first()
                        ?->company
                        ?: $user->defaultCompany()
                            ->withoutGlobalScope(CompanyScope::class)
                            ->with('partner')
                            ->first();

                    try {
                        $user->notifyNow(new RemoteCheckInReminderNotification(
                            employeeName: $user->name,
                            workDate: $today,
                            companyName: $company?->name,
                            companyLogo: $company?->logo ?: $company?->partner?->avatar,
                            companyEmail: $company?->email,
                        ));

                        $body = config('mail.default') === 'log'
                            ? __('remote-attendance::filament/pages/manage-remote-attendance.actions.test-reminder.log-note')
                            : __('remote-attendance::filament/pages/manage-remote-attendance.actions.test-reminder.sent-to', [
                                'email' => $user->email,
                            ]);

                        Notification::make()
                            ->success()
                            ->title(__('remote-attendance::filament/pages/manage-remote-attendance.actions.test-reminder.success'))
                            ->body($body)
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->warning()
                            ->title(__('remote-attendance::filament/pages/manage-remote-attendance.actions.test-reminder.failed'))
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('remote-attendance::filament/pages/manage-remote-attendance.sections.eligibility.title'))
                    ->description(__('remote-attendance::filament/pages/manage-remote-attendance.sections.eligibility.description'))
                    ->schema([
                        Toggle::make('only_home_location')
                            ->label(__('remote-attendance::filament/pages/manage-remote-attendance.form.only-home-location.label'))
                            ->helperText(__('remote-attendance::filament/pages/manage-remote-attendance.form.only-home-location.hint'))
                            ->live()
                            ->default(true),
                        ToggleButtons::make('allowed_location_types')
                            ->label(__('remote-attendance::filament/pages/manage-remote-attendance.form.allowed-location-types.label'))
                            ->helperText(__('remote-attendance::filament/pages/manage-remote-attendance.form.allowed-location-types.hint'))
                            ->options(WorkLocationEnum::class)
                            ->multiple()
                            ->inline()
                            ->visible(fn (Get $get): bool => (bool) $get('only_home_location'))
                            ->default([WorkLocationEnum::Home->value]),
                        Select::make('allowed_work_location_ids')
                            ->label(__('remote-attendance::filament/pages/manage-remote-attendance.form.allowed-work-locations.label'))
                            ->helperText(__('remote-attendance::filament/pages/manage-remote-attendance.form.allowed-work-locations.hint'))
                            ->options(static::workLocationOptions(...))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => (bool) $get('only_home_location'))
                            ->default([]),
                    ]),

                Section::make(__('remote-attendance::filament/pages/manage-remote-attendance.sections.session.title'))
                    ->description(__('remote-attendance::filament/pages/manage-remote-attendance.sections.session.description'))
                    ->schema([
                        Toggle::make('require_checkout')
                            ->label(__('remote-attendance::filament/pages/manage-remote-attendance.form.require-checkout.label'))
                            ->helperText(__('remote-attendance::filament/pages/manage-remote-attendance.form.require-checkout.hint'))
                            ->default(false),
                    ]),

                Section::make(__('remote-attendance::filament/pages/manage-remote-attendance.sections.reminders.title'))
                    ->description(__('remote-attendance::filament/pages/manage-remote-attendance.sections.reminders.description'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('reminder_enabled')
                            ->label(__('remote-attendance::filament/pages/manage-remote-attendance.form.reminder-enabled.label'))
                            ->helperText(__('remote-attendance::filament/pages/manage-remote-attendance.form.reminder-enabled.hint'))
                            ->live()
                            ->default(true),
                        TextInput::make('reminder_time')
                            ->label(__('remote-attendance::filament/pages/manage-remote-attendance.form.reminder-time.label'))
                            ->helperText(__('remote-attendance::filament/pages/manage-remote-attendance.form.reminder-time.hint'))
                            ->prefixIcon('heroicon-o-clock')
                            ->default(RemoteAttendanceSettings::DEFAULT_REMINDER_TIME)
                            ->required(fn (Get $get): bool => (bool) $get('reminder_enabled'))
                            ->visible(fn (Get $get): bool => (bool) $get('reminder_enabled'))
                            ->rule('regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/')
                            ->placeholder('09:00'),
                    ]),
            ]);
    }
}
