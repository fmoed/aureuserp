<?php

namespace Webkul\Attendance\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Webkul\Attendance\Jobs\RebuildAttendanceJob;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Services\CompanyContext;

/**
 * Queues a background rebuild of automatic attendance rows from raw events
 * (for example after shift rules or biometric barcodes change) without
 * touching manual rows or locked payroll periods.
 */
class RebuildAttendanceAction
{
    public static function make(): Action
    {
        return Action::make('rebuildAttendance')
            ->label(fn (): string => RebuildAttendanceJob::isRunning()
                ? __('attendance::filament/actions/rebuild-attendance.running-label')
                : __('attendance::filament/actions/rebuild-attendance.label'))
            ->modalHeading(__('attendance::filament/actions/rebuild-attendance.heading'))
            ->modalDescription(__('attendance::filament/actions/rebuild-attendance.description'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->disabled(fn (): bool => RebuildAttendanceJob::isRunning())
            ->extraAttributes(fn (): array => RebuildAttendanceJob::isRunning() ? ['wire:poll.3s' => '$refresh'] : [])
            ->visible(fn (): bool => static::canRebuild())
            ->authorize(fn (): bool => static::canRebuild())
            ->schema([
                DatePicker::make('date_from')
                    ->label(__('attendance::filament/actions/rebuild-attendance.date-from'))
                    ->native(false)
                    ->default(fn (): string => Carbon::today()->startOfMonth()->toDateString())
                    ->maxDate(fn (): string => Carbon::today()->toDateString())
                    ->required(),
                DatePicker::make('date_to')
                    ->label(__('attendance::filament/actions/rebuild-attendance.date-to'))
                    ->native(false)
                    ->default(fn (): string => Carbon::today()->toDateString())
                    ->afterOrEqual('date_from')
                    ->maxDate(fn (): string => Carbon::today()->toDateString())
                    ->required(),
                Select::make('calendar_id')
                    ->label(__('attendance::filament/actions/rebuild-attendance.calendar'))
                    ->options(fn (): array => Calendar::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder(__('attendance::filament/actions/rebuild-attendance.all-calendars')),
                Select::make('department_id')
                    ->label(__('attendance::filament/actions/rebuild-attendance.department'))
                    ->options(fn (): array => AttendanceOverviewService::departmentOptions())
                    ->searchable()
                    ->placeholder(__('attendance::filament/actions/rebuild-attendance.all-departments')),
                Select::make('employee_id')
                    ->label(__('attendance::filament/actions/rebuild-attendance.employee'))
                    ->options(fn (): array => AttendanceOverviewService::searchEligibleEmployees())
                    ->getSearchResultsUsing(fn (string $search): array => AttendanceOverviewService::searchEligibleEmployees($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => AttendanceOverviewService::employeeOptionLabel($value))
                    ->searchable()
                    ->placeholder(__('attendance::filament/actions/rebuild-attendance.all-employees')),
            ])
            ->action(function (array $data): void {
                if (RebuildAttendanceJob::isRunning()) {
                    Notification::make()
                        ->warning()
                        ->title(__('attendance::filament/actions/rebuild-attendance.already-running'))
                        ->send();

                    return;
                }

                Cache::put(RebuildAttendanceJob::RUNNING_KEY, auth()->id() ?? true, 300);

                RebuildAttendanceJob::dispatch(
                    dateFrom: (string) $data['date_from'],
                    dateTo: (string) $data['date_to'],
                    calendarId: filled($data['calendar_id'] ?? null) ? (int) $data['calendar_id'] : null,
                    departmentId: filled($data['department_id'] ?? null) ? (int) $data['department_id'] : null,
                    employeeId: filled($data['employee_id'] ?? null) ? (int) $data['employee_id'] : null,
                    initiatorUserId: auth()->id(),
                    companyIds: app(CompanyContext::class)->activeIds(),
                );

                Notification::make()
                    ->info()
                    ->title(__('attendance::filament/actions/rebuild-attendance.queued-title'))
                    ->body(__('attendance::filament/actions/rebuild-attendance.queued-body'))
                    ->send();
            });
    }

    protected static function canRebuild(): bool
    {
        return (bool) auth()->user()?->can('deleteAny', Attendance::class);
    }
}
