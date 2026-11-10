<?php

namespace Webkul\Attendance\Filament\Resources\AttendanceResource\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webkul\Attendance\Filament\Exports\AttendanceExporter;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Services\ShiftWindowResolver;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Support\Models\Calendar;

class AttendancesTable
{
    public const EXPORT_MAX_ROWS = 10000;

    /**
     * Stored values are naive UTC strings; always read the raw attribute so
     * the app timezone never leaks in (Carbon::parse(Carbon, 'UTC') would
     * ignore the 'UTC' argument when given an already-cast instance).
     */
    public static function employeeWallClock(Attendance $record, string $field): ?Carbon
    {
        $raw = $record->getRawOriginal($field);

        if (! $raw) {
            return null;
        }

        $timezone = $record->employee?->time_zone ?: config('app.timezone');

        return Carbon::parse($raw, 'UTC')->setTimezone($timezone);
    }

    /**
     * Night shifts check out on the calendar day after `work_date`; the
     * table marks the check-out with `(+1d)` so the pairing is unambiguous.
     */
    public static function crossesMidnight(Attendance $record): bool
    {
        $checkOut = static::employeeWallClock($record, 'check_out');

        if ($checkOut === null) {
            return false;
        }

        $workDate = $record->work_date instanceof Carbon
            ? $record->work_date->toDateString()
            : (string) $record->getRawOriginal('work_date');

        return $checkOut->toDateString() > $workDate;
    }

    /**
     * Closes an open row by recording its check-out. Shared by the table
     * row actions and the view page header so the validation logic lives
     * in exactly one place.
     */
    public static function makeAddCheckoutAction(): Action
    {
        return Action::make('add_checkout')
            ->label(__('attendance::filament/resources/attendance.table.record-actions.add-checkout'))
            ->icon('heroicon-o-arrow-right-end-on-rectangle')
            ->color('success')
            ->modalDescription(__('attendance::filament/resources/attendance.form.checkout-hint', [
                'hours' => AttendanceSettings::maxShiftHours(),
            ]))
            ->visible(fn (Attendance $record): bool => $record->check_out === null && auth()->user()?->can('update', $record))
            ->schema([
                DateTimePicker::make('check_out')
                    ->label(__('attendance::filament/resources/attendance.form.check-out'))
                    ->required()
                    ->seconds()
                    ->default(function (Attendance $record): ?string {
                        $fallback = static::employeeWallClock($record, 'check_in')?->format('Y-m-d H:i:s');

                        if (! $record->employee || ! $record->work_date) {
                            return $fallback;
                        }

                        $resolver = app(ShiftWindowResolver::class);
                        $calendar = $resolver->resolveCalendarFor($record->employee);

                        if (! $calendar) {
                            return $fallback;
                        }

                        $timezone = $record->employee->time_zone ?: config('app.timezone');
                        $windows = $resolver->windowsForDate($calendar, $record->work_date->toDateString(), $timezone);

                        if (empty($windows)) {
                            return $fallback;
                        }

                        $lastWindow = end($windows);
                        $shiftEnd = $lastWindow['shift_end'] ?? null;
                        $checkInLocal = static::employeeWallClock($record, 'check_in');

                        if ($shiftEnd instanceof Carbon && $checkInLocal && $shiftEnd->gt($checkInLocal)) {
                            return $shiftEnd->format('Y-m-d H:i:s');
                        }

                        return $fallback;
                    }),
            ])
            ->action(function (Attendance $record, array $data): void {
                Gate::authorize('update', $record);

                $timezone = $record->employee?->time_zone ?: config('app.timezone');
                $checkOut = Carbon::parse($data['check_out'], $timezone);
                $checkIn = Carbon::parse($record->getRawOriginal('check_in'), 'UTC');

                if ($checkOut->lt($checkIn)) {
                    throw ValidationException::withMessages([
                        'check_out' => __('attendance::filament/resources/attendance.form.check-out-before-check-in'),
                    ]);
                }

                if (! Attendance::isValidCheckout($checkIn, $checkOut)) {
                    throw ValidationException::withMessages([
                        'check_out' => __('attendance::filament/resources/attendance.form.check-out-not-in-work-date', [
                            'hours' => AttendanceSettings::maxShiftHours(),
                        ]),
                    ]);
                }

                $record->update(['check_out' => $checkOut->setTimezone('UTC')->format('Y-m-d H:i:s')]);

                $minutes = $checkIn->diffInMinutes($checkOut);

                if ($minutes > Attendance::LONG_SHIFT_MINUTES) {
                    Notification::make()
                        ->warning()
                        ->title(__('attendance::filament/resources/attendance.form.long-shift-warning', [
                            'hours' => round($minutes / 60, 1),
                        ]))
                        ->send();
                }
            });
    }

    protected static ?array $cachedSources = null;

    public static function sourceOptions(): array
    {
        if (static::$cachedSources !== null) {
            return static::$cachedSources;
        }

        $options = [
            Attendance::SOURCE_MANUAL => __('attendance::filament/resources/attendance.sources.manual'),
        ];

        foreach (WriterRegistry::slugs() as $slug) {
            $options[$slug] = WriterRegistry::resolveLabel($slug) ?? Attendance::sourceDisplayName($slug, null);
        }

        $dbSources = AttendancePolicy::scopeQuery(Attendance::query())
            ->select('source')
            ->selectRaw('MAX(source_label) AS source_label')
            ->groupBy('source')
            ->get()
            ->mapWithKeys(fn (Attendance $row) => [
                $row->source => Attendance::sourceDisplayName($row->source, $row->source_label),
            ])
            ->all();

        return static::$cachedSources = array_merge($options, $dbSources);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('work_date', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50])
            ->modifyQueryUsing(fn (Builder $query): Builder => AttendancePolicy::scopeQuery($query)->with('employee'))
            ->columns([
                TextColumn::make('employee.name')
                    ->label(__('attendance::filament/resources/attendance.table.columns.employee'))
                    ->sortable()
                    ->searchable()
                    ->url(fn (Attendance $record): ?string => $record->employee && auth()->user()?->can('view', $record->employee)
                        ? EmployeeResource::getUrl('view', ['record' => $record->employee])
                        : null),
                TextColumn::make('work_date')
                    ->label(__('attendance::filament/resources/attendance.table.columns.work-date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('check_in')
                    ->label(__('attendance::filament/resources/attendance.table.columns.check-in'))
                    ->state(fn (Attendance $record) => static::employeeWallClock($record, 'check_in'))
                    ->dateTime()
                    ->sortable()
                    ->tooltip(fn (Attendance $record): string => __('attendance::filament/resources/attendance.table.filters.timezone-tip', [
                        'zone' => $record->employee?->time_zone ?: config('app.timezone'),
                    ])),
                TextColumn::make('check_out')
                    ->label(__('attendance::filament/resources/attendance.table.columns.check-out'))
                    ->state(fn (Attendance $record) => static::employeeWallClock($record, 'check_out'))
                    ->dateTime()
                    ->sortable()
                    ->tooltip(fn (Attendance $record): string => __('attendance::filament/resources/attendance.table.filters.timezone-tip', [
                        'zone' => $record->employee?->time_zone ?: config('app.timezone'),
                    ]))
                    ->placeholder('—'),
                TextColumn::make('next_day')
                    ->label(__('attendance::filament/resources/attendance.table.columns.next-day'))
                    ->state(fn (Attendance $record): ?string => static::crossesMidnight($record) ? '(+1d)' : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder(''),
                TextColumn::make('worked_minutes')
                    ->label(__('attendance::filament/resources/attendance.table.columns.worked'))
                    ->state(fn (Attendance $record) => $record->worked_minutes)
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : intdiv((int) $state, 60).'h '.((int) $state % 60).'m'),
                TextColumn::make('source')
                    ->label(__('attendance::filament/resources/attendance.table.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, Attendance $record): string => Attendance::sourceDisplayName($state, $record->source_label))
                    ->color(fn (string $state): string => $state === Attendance::SOURCE_MANUAL ? 'success' : 'info')
                    ->sortable(),
                TextColumn::make('is_estimated')
                    ->label(__('attendance::filament/resources/attendance.table.columns.estimated'))
                    ->state(fn (Attendance $record): ?string => $record->is_estimated
                        ? __('attendance::filament/resources/attendance.table.columns.estimated')
                        : null)
                    ->badge()
                    ->color('info')
                    ->placeholder(''),
                TextColumn::make('created_at')
                    ->label(__('attendance::filament/resources/attendance.table.columns.created-at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label(__('attendance::filament/resources/attendance.table.filters.employee'))
                    ->relationship(
                        'employee',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => AttendancePolicy::scopeEmployeeQuery($query),
                    )
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('department_id')
                    ->label(__('attendance::filament/resources/attendance.table.filters.department'))
                    ->options(fn (): array => AttendanceOverviewService::departmentOptions())
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $departmentIds = $data['values'] ?? ($data['value'] ?? []);

                        return $query->when(
                            filled($departmentIds),
                            fn (Builder $query) => $query->whereHas(
                                'employee',
                                fn (Builder $employee) => AttendanceOverviewService::scopeByDepartment($employee, (array) $departmentIds),
                            ),
                        );
                    }),
                SelectFilter::make('calendar_id')
                    ->label(__('attendance::filament/resources/attendance.table.filters.calendar'))
                    ->options(fn (): array => Calendar::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $calendarIds = $data['values'] ?? ($data['value'] ?? []);

                        return $query->when(
                            filled($calendarIds),
                            fn (Builder $query) => $query->whereHas(
                                'employee',
                                fn (Builder $employee) => AttendanceOverviewService::scopeByCalendar($employee, (array) $calendarIds),
                            ),
                        );
                    }),
                SelectFilter::make('source')
                    ->label(__('attendance::filament/resources/attendance.table.filters.source'))
                    ->options(fn (): array => static::sourceOptions())
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $sources = $data['values'] ?? ($data['value'] ?? []);

                        return $query->when(
                            filled($sources),
                            fn (Builder $query) => $query->whereIn('source', (array) $sources),
                        );
                    }),
                SelectFilter::make('session_status')
                    ->label(__('attendance::filament/resources/attendance.table.filters.session-status'))
                    ->options([
                        'open'   => __('attendance::filament/resources/attendance.table.filters.status-open'),
                        'closed' => __('attendance::filament/resources/attendance.table.filters.status-closed'),
                        'long'   => __('attendance::filament/resources/attendance.table.filters.status-long'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'open'   => $query->whereNull('check_out'),
                            'closed' => $query->whereNotNull('check_out'),
                            'long'   => $query->whereNotNull('check_in')
                                ->whereNotNull('check_out')
                                ->whereRaw(
                                    db_dialect()->minutesBetween('check_in', 'check_out').' > ?',
                                    [Attendance::LONG_SHIFT_MINUTES],
                                ),
                            default  => $query,
                        };
                    }),
                Filter::make('work_date')
                    ->label(__('attendance::filament/resources/attendance.table.filters.work-date'))
                    ->schema([
                        Select::make('preset')
                            ->label(__('attendance::filament/resources/attendance.table.filters.preset'))
                            ->options([
                                'today'      => __('attendance::filament/resources/attendance.table.filters.preset-today'),
                                'this_week'  => __('attendance::filament/resources/attendance.table.filters.preset-this-week'),
                                'this_month' => __('attendance::filament/resources/attendance.table.filters.preset-this-month'),
                            ])
                            ->helperText(__('attendance::filament/resources/attendance.table.filters.preset-hint'))
                            ->live(),
                        DatePicker::make('work_date_from')
                            ->label(__('attendance::filament/resources/attendance.table.filters.date-from'))
                            ->visible(fn (Get $get): bool => blank($get('preset'))),
                        DatePicker::make('work_date_to')
                            ->label(__('attendance::filament/resources/attendance.table.filters.date-to'))
                            ->visible(fn (Get $get): bool => blank($get('preset'))),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['preset'])) {
                            [$from, $to] = match ($data['preset']) {
                                'today'      => [Carbon::today()->toDateString(), Carbon::today()->toDateString()],
                                'this_week'  => [Carbon::now()->startOfWeek()->toDateString(), Carbon::now()->endOfWeek()->toDateString()],
                                default      => [Carbon::now()->startOfMonth()->toDateString(), Carbon::now()->endOfMonth()->toDateString()],
                            };

                            return $query->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to);
                        }

                        return $query
                            ->when($data['work_date_from'] ?? null, fn ($query, string $date) => $query->whereDate('work_date', '>=', $date))
                            ->when($data['work_date_to'] ?? null, fn ($query, string $date) => $query->whereDate('work_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                static::makeAddCheckoutAction(),
                EditAction::make()
                    ->visible(fn (Attendance $record): bool => $record->source === Attendance::SOURCE_MANUAL
                        && $record->check_out !== null
                        && (bool) auth()->user()?->can('update', $record)),
                DeleteAction::make()
                    ->visible(fn (Attendance $record): bool => (bool) auth()->user()?->can('delete', $record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    AttendanceExporter::configureExportAction(
                        ExportBulkAction::make()
                            ->label(__('attendance::filament/resources/attendance.table.toolbar-actions.export-selected'))
                            ->icon('heroicon-o-arrow-up-tray')
                            ->exporter(AttendanceExporter::class)
                            ->maxRows(self::EXPORT_MAX_ROWS)
                            ->visible(fn (): bool => AttendanceResource::canViewAny())
                            ->authorize(fn (): bool => AttendanceResource::canViewAny()),
                    ),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => (bool) auth()->user()?->can('delete_any_attendance_attendance'))
                        ->authorize(fn (): bool => (bool) auth()->user()?->can('delete_any_attendance_attendance'))
                        ->action(function (Collection $records): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->source === Attendance::SOURCE_MANUAL && auth()->user()?->can('delete', $record)) {
                                    $record->delete();

                                    $deleted++;

                                    continue;
                                }

                                $skipped++;
                            }

                            if ($deleted > 0) {
                                Notification::make()
                                    ->success()
                                    ->title(__('attendance::filament/resources/attendance.table.bulk-actions.deleted', ['count' => $deleted]))
                                    ->send();
                            }

                            if ($skipped > 0) {
                                Notification::make()
                                    ->warning()
                                    ->title(__('attendance::filament/resources/attendance.table.bulk-actions.skipped', ['count' => $skipped]))
                                    ->send();
                            }
                        }),
                ]),
                AttendanceExporter::configureExportAction(
                    ExportAction::make()
                        ->label(__('attendance::filament/resources/attendance.table.toolbar-actions.export'))
                        ->icon('heroicon-o-arrow-up-tray')
                        ->exporter(AttendanceExporter::class)
                        ->maxRows(self::EXPORT_MAX_ROWS)
                        ->visible(fn (): bool => AttendanceResource::canViewAny())
                        ->authorize(fn (): bool => AttendanceResource::canViewAny())
                        ->modifyQueryUsing(fn (Builder $query, array $options): Builder => AttendanceExporter::scopeQuery($query, $options)),
                ),
            ]);
    }
}
