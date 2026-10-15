<?php

namespace Webkul\Attendance\Filament\Resources\AttendanceEventResource\Tables;

use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Filament\Exports\AttendanceEventExporter;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Services\AttendanceOverviewService;

class AttendanceEventsTable
{
    /**
     * Hard cap for event exports: the device log is unbounded, so an
     * unfiltered export must never stream the whole table into one file.
     * Filament rejects oversized exports with a message natively.
     */
    public const EXPORT_MAX_ROWS = 10000;

    /**
     * Stored punches are naive UTC strings; always read the raw attribute so
     * the app timezone never leaks in (mirrors AttendancesTable::employeeWallClock).
     */
    public static function eventWallClock(AttendanceEvent $event): ?Carbon
    {
        $raw = $event->getRawOriginal('punched_at');

        if (! $raw) {
            return null;
        }

        $timezone = $event->employee?->time_zone ?: config('app.timezone');

        return Carbon::parse($raw, 'UTC')->setTimezone($timezone);
    }

    /**
     * Check-in of the row this event was paired into, shown in the event
     * employee's wall clock. Null while the event is still unpaired.
     */
    public static function linkedCheckIn(AttendanceEvent $event): ?Carbon
    {
        $raw = $event->attendance?->getRawOriginal('check_in');

        if (! $raw) {
            return null;
        }

        $timezone = $event->employee?->time_zone ?: config('app.timezone');

        return Carbon::parse($raw, 'UTC')->setTimezone($timezone);
    }

    /**
     * A punch date range wider than MAX_RANGE_DAYS is rejected with a
     * validation error (ListAttendanceEvents::updatedTableFilters); the
     * query guard skips the bounds so a rejected range never silently
     * hides rows either.
     */
    public static function rangeIsOversized(array $data): bool
    {
        $from = $data['punched_from'] ?? null;
        $to = $data['punched_to'] ?? null;

        if (! $from || ! $to) {
            return false;
        }

        return abs(Carbon::parse($from)->diffInDays($to)) > AttendanceOverviewService::MAX_RANGE_DAYS;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('punched_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50])
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'attendance']))
            ->columns([
                TextColumn::make('employee.name')
                    ->label(__('attendance::filament/resources/attendance-event.table.columns.employee'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('punched_at')
                    ->label(__('attendance::filament/resources/attendance-event.table.columns.punched-at'))
                    ->state(fn (AttendanceEvent $event) => static::eventWallClock($event))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('direction')
                    ->label(__('attendance::filament/resources/attendance-event.table.columns.direction'))
                    ->state(fn (AttendanceEvent $event) => AttendanceEvent::directionLabel($event->direction))
                    ->badge()
                    ->color(fn (AttendanceEvent $event): string => match ($event->direction) {
                        AttendanceEvent::DIRECTION_IN   => 'success',
                        AttendanceEvent::DIRECTION_OUT  => 'warning',
                        default                         => 'gray',
                    }),
                TextColumn::make('source')
                    ->label(__('attendance::filament/resources/attendance-event.table.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, AttendanceEvent $event): string => Attendance::sourceDisplayName($state, $event->source_label))
                    ->color('info')
                    ->sortable(),
                TextColumn::make('attendance.check_in')
                    ->label(__('attendance::filament/resources/attendance-event.table.columns.linked-row'))
                    ->state(fn (AttendanceEvent $event) => static::linkedCheckIn($event))
                    ->dateTime()
                    ->tooltip(__('attendance::filament/resources/attendance-event.table.columns.linked-hint'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label(__('attendance::filament/resources/attendance-event.table.filters.employee'))
                    ->relationship('employee', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('source')
                    ->label(__('attendance::filament/resources/attendance-event.table.filters.source'))
                    ->options(fn (): array => AttendanceEvent::query()
                        ->select('source')
                        ->selectRaw('MAX(source_label) AS source_label')
                        ->groupBy('source')
                        ->get()
                        ->mapWithKeys(fn (AttendanceEvent $row) => [
                            $row->source => Attendance::sourceDisplayName($row->source, $row->source_label),
                        ])
                        ->all()),
                Filter::make('punched_at')
                    ->label(__('attendance::filament/resources/attendance-event.table.filters.punched-at'))
                    ->form([
                        DatePicker::make('punched_from')
                            ->label(__('attendance::filament/resources/attendance-event.table.filters.date-from')),
                        DatePicker::make('punched_to')
                            ->label(__('attendance::filament/resources/attendance-event.table.filters.date-to')),
                    ])
                    ->query(function ($query, array $data) {
                        if (static::rangeIsOversized($data)) {
                            return $query;
                        }

                        return $query
                            ->when($data['punched_from'] ?? null, fn ($query, string $date) => $query->whereDate('punched_at', '>=', $date))
                            ->when($data['punched_to'] ?? null, fn ($query, string $date) => $query->whereDate('punched_at', '<=', $date));
                    }),
            ])
            ->toolbarActions([
                ExportAction::make()
                    ->label(__('attendance::filament/resources/attendance-event.table.toolbar-actions.export'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->exporter(AttendanceEventExporter::class)
                    ->maxRows(self::EXPORT_MAX_ROWS),
            ]);
    }
}
