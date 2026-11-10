<?php

namespace Webkul\Attendance\Filament\Tables;

use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;

/**
 * Attendance register: one row per employee. The page
 * picks the column set through registerView: day-by-day cells for the
 * applied display range, or the per-employee totals instead of the wide
 * day grid. Every day up to today carries an explicit colored status
 * word. Days beyond today render blank. The page supplies display-status
 * cells through getGrid(); this class only maps them to localized labels
 * and colors.
 */
class AttendanceRegisterTable
{
    public static function configure(Table $table, AttendanceOverview $page): Table
    {
        $grid = collect($page->getGrid())->keyBy('employee_id');

        $nameColumn = TextColumn::make('name')
            ->label(__('attendance::filament/pages/overview.table.columns.employee'))
            ->size(TextSize::Small)
            ->width('180px')
            ->searchable()
            ->sortable()
            ->url(fn (Employee $record): ?string => auth()->user()?->can('view', $record)
                ? EmployeeResource::getUrl('view', ['record' => $record])
                : null)
            ->tooltip(fn (Employee $record): ?string => $record->job_title ?: null)
            ->extraHeaderAttributes(['class' => 'fi-reg-sticky'])
            ->extraCellAttributes(fn (Employee $record): array => [
                'class' => 'fi-reg-sticky '.(preg_match('/\p{Arabic}/u', (string) $record->name) ? 'fi-reg-name-rtl' : 'fi-reg-name-ltr'),
                'title' => (string) $record->name,
            ]);

        $scheduleColumn = TextColumn::make('schedule')
            ->label(__('attendance::filament/pages/overview.table.columns.schedule'))
            ->state(fn (Employee $record): string => (string) ($grid[$record->getKey()]['calendar'] ?? ''))
            ->size(TextSize::Small)
            ->width('10rem')
            ->url(function (Employee $record) use ($grid): ?string {
                $calendarId = $grid[$record->getKey()]['calendar_id']
                    ?? $record->calendar_id;

                if (! $calendarId) {
                    return null;
                }

                if (class_exists(CalendarResource::class)
                    && (auth()->user()?->can('view_any_support_calendar') || auth()->user()?->can('view_support_calendar'))) {
                    return CalendarResource::getUrl('view', ['record' => $calendarId]);
                }

                return null;
            })
            ->toggleable()
            ->visibleFrom('md');

        if ($page->registerView === AttendanceOverview::REGISTER_TOTALS || $page->rangeMode === 'year') {
            // Totals sit next to the name; the long roster name goes last so
            // the eye connects each row of numbers to its employee at once.
            if ($page->rangeMode === 'year') {
                return self::baseTable($table, $page, $grid, array_merge([$nameColumn], self::buildYearColumns($grid), [$scheduleColumn]));
            }

            return self::baseTable($table, $page, $grid, array_merge([$nameColumn], self::totalsColumns($grid), [$scheduleColumn]));
        }

        $columns = [$nameColumn, $scheduleColumn];

        foreach ($page->getGridDays() as $day) {
            $columns[] = TextColumn::make('cells.'.$day)
                ->label(Carbon::parse($day)->translatedFormat('D j'))
                ->wrapHeader()
                ->state(fn (Employee $record): string => (string) ($grid[$record->getKey()]['cells'][$day] ?? ''))
                ->formatStateUsing(fn (mixed $state): string => self::statusShortLabel((string) $state))
                ->tooltip(fn (mixed $state): ?string => filled($state) && $state !== AttendanceOverviewService::STATUS_FUTURE
                    ? self::statusLabel((string) $state)
                    : null)
                ->color(fn (mixed $state): ?string => self::statusColor((string) $state))
                ->size(TextSize::Small)
                ->alignCenter()
                ->width('2.75rem')
                ->extraHeaderAttributes(['class' => 'fi-reg-day-header'])
                ->extraCellAttributes(function (Employee $record) use ($day): array {
                    $isPastOrToday = $day <= Carbon::today()->toDateString();
                    $cellClass = 'fi-reg-day-cell';

                    if ($isPastOrToday) {
                        $cellClass .= ' cursor-pointer hover:ring-1 hover:ring-primary-500/40 hover:bg-primary-50/50 dark:hover:bg-primary-950/30';
                    }

                    $attributes = [
                        'class' => $cellClass,
                    ];

                    if ($isPastOrToday) {
                        $attributes['wire:click'] = "mountAction('inspectDayCell', { employee_id: {$record->getKey()}, date: '{$day}' })";
                    }

                    return array_filter($attributes, fn (mixed $value): bool => $value !== null);
                })
                ->toggleable()
                ->placeholder('');
        }

        return self::baseTable($table, $page, $grid, $columns);
    }

    /**
     * Per-employee totals columns (the compact "summary" view of the
     * register): visible present/absent/leave/rate plus the extra
     * pending/holidays/workdays and the hours breakdown behind the column
     * toggle. Numeric columns carry a fixed width so the summary stays
     * dense: a full-width spread leaves each value floating far from its
     * header in RTL.
     *
     * @param  Collection<int, array>  $grid
     * @return list<TextColumn>
     */
    protected static function totalsColumns(mixed $grid): array
    {
        $columns = [];

        $columns[] = TextColumn::make('totals.workdays')
            ->label(__('attendance::filament/pages/overview.table.columns.workdays'))
            ->state(fn (Employee $record): float => (float) ($grid[$record->getKey()]['totals']['workdays'] ?? 0))
            ->formatStateUsing(fn (mixed $state): string => self::formatCount($state))
            ->size(TextSize::Small)
            ->alignCenter()
            ->width('110px')
            ->toggleable();

        foreach ([
            'present'      => __('attendance::filament/pages/overview.grid.totals.present'),
            'absent'       => __('attendance::filament/pages/overview.grid.totals.absent'),
            'on_leave'     => __('attendance::filament/pages/overview.grid.totals.on-leave'),
            'absence_rate' => __('attendance::filament/pages/overview.table.columns.rate'),
        ] as $total => $label) {
            $column = TextColumn::make('totals.'.$total)
                ->label($label)
                ->state(fn (Employee $record): float => (float) ($grid[$record->getKey()]['totals'][$total] ?? 0))
                ->formatStateUsing(fn (mixed $state): string => $total === 'absence_rate'
                    ? ((float) $state).'%'
                    : self::formatCount($state))
                ->size(TextSize::Small)
                ->alignCenter()
                ->width('110px')
                ->toggleable();

            if ($total === 'present') {
                $column->tooltip(function (Employee $record) use ($grid): ?string {
                    $rowTotals = $grid[$record->getKey()]['totals'] ?? [];
                    $parts = [];

                    if ((int) ($rowTotals['half_days'] ?? 0) > 0) {
                        $parts[] = __('attendance::filament/pages/overview.statuses.half-day').': '.(int) $rowTotals['half_days'];
                    }

                    if ((int) ($rowTotals['weekend_worked_days'] ?? 0) > 0) {
                        $parts[] = __('attendance::filament/pages/overview.statuses.weekend-present').': '.(int) $rowTotals['weekend_worked_days'];
                    }

                    if ((int) ($rowTotals['holiday_worked_days'] ?? 0) > 0) {
                        $parts[] = __('attendance::filament/pages/overview.statuses.holiday-present').': '.(int) $rowTotals['holiday_worked_days'];
                    }

                    return $parts !== [] ? implode(' · ', $parts) : null;
                });
            }

            $columns[] = $column;
        }

        $columns[] = self::minutesColumn(
            'totals.minutes',
            __('attendance::filament/pages/overview.table.columns.worked-hours'),
            fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['minutes'] ?? 0),
        )
            ->tooltip(function (Employee $record) use ($grid): string {
                $rowTotals = $grid[$record->getKey()]['totals'] ?? [];
                $scheduled = self::formatMinutesToHours((int) ($rowTotals['scheduled_minutes'] ?? 0));
                $variance = self::formatMinutesToHours((int) ($rowTotals['variance_minutes'] ?? 0), true);

                return __('attendance::filament/pages/overview.table.columns.scheduled-hours').': '.$scheduled
                    .' · '.__('attendance::filament/pages/overview.table.columns.variance-hours').': '.$variance;
            })
            ->toggleable();

        $columns[] = self::minutesColumn(
            'totals.overtime_minutes',
            __('attendance::filament/pages/overview.table.columns.overtime-hours'),
            fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['overtime_minutes'] ?? 0),
        )->toggleable();

        $columns[] = self::minutesColumn(
            'totals.late_minutes',
            __('attendance::filament/pages/overview.table.columns.late-minutes'),
            fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['late_minutes'] ?? 0),
        )
            ->tooltip(function (Employee $record) use ($grid): ?string {
                $count = (int) ($grid[$record->getKey()]['totals']['late_count'] ?? 0);

                return $count > 0
                    ? __('attendance::filament/pages/overview.table.columns.late-occurrences', ['count' => $count])
                    : null;
            })
            ->toggleable();

        $columns[] = self::minutesColumn(
            'totals.scheduled_minutes',
            __('attendance::filament/pages/overview.table.columns.scheduled-hours'),
            fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['scheduled_minutes'] ?? 0),
        )->toggleable(isToggledHiddenByDefault: true);

        $columns[] = TextColumn::make('totals.variance_minutes')
            ->label(__('attendance::filament/pages/overview.table.columns.variance-hours'))
            ->state(fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['variance_minutes'] ?? 0))
            ->formatStateUsing(fn (mixed $state): string => self::formatMinutesToHours((int) $state, true))
            ->color(fn (mixed $state): string => (int) $state < 0 ? 'danger' : 'success')
            ->size(TextSize::Small)
            ->alignCenter()
            ->width('100px')
            ->toggleable(isToggledHiddenByDefault: true);

        $columns[] = self::minutesColumn(
            'totals.early_leave_minutes',
            __('attendance::filament/pages/overview.table.columns.early-leave'),
            fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['early_leave_minutes'] ?? 0),
        )->toggleable(isToggledHiddenByDefault: true);

        $columns[] = TextColumn::make('totals.missing_checkouts')
            ->label(__('attendance::filament/pages/overview.table.columns.missing-checkouts'))
            ->state(fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['missing_checkouts'] ?? 0))
            ->formatStateUsing(fn (mixed $state): string => (string) (int) $state)
            ->color(fn (mixed $state): ?string => (int) $state > 0 ? 'warning' : null)
            ->size(TextSize::Small)
            ->alignCenter()
            ->width('100px')
            ->toggleable(isToggledHiddenByDefault: true);

        foreach ([
            'pending'  => __('attendance::filament/pages/overview.grid.totals.pending'),
            'holidays' => __('attendance::filament/pages/overview.grid.totals.holidays'),
        ] as $total => $label) {
            $columns[] = TextColumn::make('totals.'.$total)
                ->label($label)
                ->state(fn (Employee $record): float => (float) ($grid[$record->getKey()]['totals'][$total] ?? 0))
                ->formatStateUsing(fn (mixed $state): string => self::formatCount($state))
                ->size(TextSize::Small)
                ->alignCenter()
                ->width('110px')
                ->toggleable(isToggledHiddenByDefault: true);
        }

        return $columns;
    }

    /**
     * Monthly cells for the yearly register: one column per elapsed month,
     * each cell pairing worked days with worked hours (`20d · 158h`) and
     * carrying the full month breakdown in its native title tooltip, plus
     * trailing yearly totals.
     *
     * @param  Collection<int, array>  $grid
     * @return list<TextColumn>
     */
    protected static function buildYearColumns(mixed $grid): array
    {
        $monthStarts = $grid
            ->flatMap(fn (array $row): array => array_keys($row['cells'] ?? []))
            ->unique()
            ->sort()
            ->values();

        $columns = [];

        foreach ($monthStarts as $monthStart) {
            $columns[] = TextColumn::make('months.'.$monthStart)
                ->label(Carbon::parse($monthStart)->translatedFormat('M Y'))
                ->state(fn (Employee $record): array => $grid[$record->getKey()]['cells'][$monthStart] ?? [])
                ->formatStateUsing(fn (mixed $state): string => is_array($state) ? self::formatYearCell($state) : '')
                ->tooltip(fn (mixed $state): ?string => is_array($state) ? self::yearCellDetails($monthStart, $state) : null)
                ->size(TextSize::Small)
                ->alignCenter()
                ->width('7rem')
                ->extraHeaderAttributes(['class' => 'fi-reg-day-header']);
        }

        $columns[] = TextColumn::make('totals.present')
            ->label(__('attendance::filament/pages/overview.grid.totals.present'))
            ->state(fn (Employee $record): float => (float) ($grid[$record->getKey()]['totals']['present'] ?? 0))
            ->formatStateUsing(fn (mixed $state): string => self::formatCount($state))
            ->size(TextSize::Small)
            ->alignCenter()
            ->width('110px')
            ->toggleable();

        $columns[] = TextColumn::make('totals.absent')
            ->label(__('attendance::filament/pages/overview.grid.totals.absent'))
            ->state(fn (Employee $record): float => (float) ($grid[$record->getKey()]['totals']['absent'] ?? 0))
            ->formatStateUsing(fn (mixed $state): string => self::formatCount($state))
            ->size(TextSize::Small)
            ->alignCenter()
            ->width('110px')
            ->toggleable();

        $columns[] = self::minutesColumn(
            'totals.minutes',
            __('attendance::filament/pages/overview.table.columns.worked-hours'),
            fn (Employee $record): int => (int) ($grid[$record->getKey()]['totals']['minutes'] ?? 0),
        )->toggleable();

        return $columns;
    }

    /**
     * @param  callable(Employee): int  $state
     */
    protected static function minutesColumn(string $name, string $label, callable $state): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->state(fn (Employee $record): int => $state($record))
            ->formatStateUsing(fn (mixed $state): string => self::formatMinutesToHours((int) $state))
            ->size(TextSize::Small)
            ->alignCenter()
            ->width('90px');
    }

    /**
     * Weighted day counts keep halves visible (`21.5`) instead of silently
     * truncating a half-day leave to a whole day.
     */
    public static function formatCount(mixed $value): string
    {
        $float = (float) $value;

        return abs($float - round($float)) < 0.0001
            ? (string) (int) round($float)
            : rtrim(rtrim(number_format($float, 1, '.', ''), '0'), '.');
    }

    /**
     * Standard register duration format: `160:00` for totals and
     * `+04:30` / `-02:15` when the sign carries meaning (variance).
     */
    public static function formatMinutesToHours(int $minutes, bool $signed = false): string
    {
        $sign = $signed && $minutes !== 0 ? ($minutes > 0 ? '+' : '-') : '';
        $absolute = abs($minutes);

        return sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60);
    }

    /**
     * @param  array{present?: int|float, minutes?: int, scheduled_minutes?: int, overtime_minutes?: int}  $cell
     */
    public static function formatYearCell(array $cell): string
    {
        $days = self::formatCount($cell['present'] ?? 0);
        $hours = intdiv((int) ($cell['minutes'] ?? 0), 60);

        return $days.'d · '.$hours.'h';
    }

    /**
     * @param  array{present?: int|float, absent?: int|float, on_leave?: int|float, minutes?: int, scheduled_minutes?: int, overtime_minutes?: int}  $cell
     */
    public static function yearCellDetails(string $monthStart, array $cell): string
    {
        $parts = [
            __('attendance::filament/pages/overview.grid.totals.present').': '.self::formatCount($cell['present'] ?? 0),
            __('attendance::filament/pages/overview.grid.totals.absent').': '.self::formatCount($cell['absent'] ?? 0),
            __('attendance::filament/pages/overview.grid.totals.on-leave').': '.self::formatCount($cell['on_leave'] ?? 0),
            __('attendance::filament/pages/overview.table.columns.worked-hours').': '.self::formatMinutesToHours((int) ($cell['minutes'] ?? 0)),
            __('attendance::filament/pages/overview.table.columns.scheduled-hours').': '.self::formatMinutesToHours((int) ($cell['scheduled_minutes'] ?? 0)),
            __('attendance::filament/pages/overview.table.columns.overtime-hours').': '.self::formatMinutesToHours((int) ($cell['overtime_minutes'] ?? 0)),
        ];

        return Carbon::parse($monthStart)->translatedFormat('F Y').' — '.implode(' · ', $parts);
    }

    /**
     * @param  Collection<int, array>  $grid
     * @param  list<TextColumn>  $columns
     */
    protected static function baseTable(Table $table, AttendanceOverview $page, mixed $grid, array $columns): Table
    {
        return $table
            ->query(fn (): Builder => Employee::query()
                ->whereIn('id', $grid->keys()->all()))
            ->columns($columns)
            ->filters([
                Filter::make('overview')
                    ->columns(2)
                    ->schema(fn (): array => $page->getFilterFormComponents())
                    ->indicateUsing(fn (): array => $page->getTableFilterIndicators()),
            ])
            ->filtersFormWidth(Width::Medium)
            ->defaultSort('name')
            ->persistSortInSession()
            ->persistSearchInSession()
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions(AttendanceOverview::PER_PAGE_OPTIONS)
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading(__('attendance::filament/pages/overview.empty.heading'))
            ->emptyStateDescription(__('attendance::filament/pages/overview.table.empty'));
    }

    /**
     * Day detail carried on the cell's native title attribute: present days
     * show the wall-clock in/out (with `(+1d)` when the shift crosses
     * midnight), worked/scheduled hours, breaks, late minutes and overtime.
     * The widget's own tooltip stays the bare status word (a stable contract
     * for tests and screen readers), so this rides alongside it.
     *
     * @param  array{check_in: ?string, check_out: ?string, minutes: ?int, scheduled_minutes?: ?int, break_minutes?: ?int, overtime_minutes?: ?int, late_minutes?: ?int, early_leave_minutes?: ?int, variance_minutes?: ?int, is_estimated?: bool, leave_label: ?string}|null  $meta
     */
    public static function dayDetails(?string $status, ?array $meta, ?string $workDate = null): ?string
    {
        if ($status === null || $status === '' || $status === AttendanceOverviewService::STATUS_FUTURE || $meta === null) {
            return null;
        }

        $label = self::statusLabel($status);

        if (
            in_array($status, [
                AttendanceOverviewService::STATUS_ON_LEAVE,
                AttendanceOverviewService::STATUS_HALF_DAY,
                AttendanceOverviewService::STATUS_PENDING,
            ], true)
            && filled($meta['leave_label'] ?? null)
        ) {
            return $label.' — '.$meta['leave_label'];
        }

        $parts = [];

        $attended = in_array($status, [
            AttendanceOverviewService::STATUS_PRESENT,
            AttendanceOverviewService::STATUS_WEEKEND_PRESENT,
            AttendanceOverviewService::STATUS_HOLIDAY_PRESENT,
            AttendanceOverviewService::STATUS_MISSING_CHECKOUT,
        ], true);

        $checkIn = $attended ? self::clockTime($meta['check_in'] ?? null) : null;
        $checkOut = $attended ? self::clockTime($meta['check_out'] ?? null) : null;

        if ($checkIn !== null || $checkOut !== null) {
            $nextDay = $workDate !== null
                && filled($meta['check_out'] ?? null)
                && Carbon::parse($meta['check_out'])->toDateString() !== $workDate;

            $parts[] = ($checkIn ?? '—').' → '.($checkOut ?? '—').($nextDay ? ' (+1d)' : '');
        }

        if ($attended && ($meta['minutes'] ?? null) !== null) {
            $hours = self::formatMinutesToHours((int) $meta['minutes']);

            if (($meta['scheduled_minutes'] ?? null) !== null) {
                $hours .= ' / '.self::formatMinutesToHours((int) $meta['scheduled_minutes']);
            }

            $parts[] = $hours;
        }

        if ($attended && (int) ($meta['break_minutes'] ?? 0) > 0) {
            $parts[] = __('attendance::filament/pages/overview.table.columns.break').': '.(int) $meta['break_minutes'].'m';
        }

        if ($attended && (int) ($meta['late_minutes'] ?? 0) > 0) {
            $parts[] = __('attendance::filament/pages/overview.table.columns.late').': '.(int) $meta['late_minutes'].'m';
        }

        if ($attended && (int) ($meta['early_leave_minutes'] ?? 0) > 0) {
            $parts[] = __('attendance::filament/pages/overview.table.columns.early-leave').': '.(int) $meta['early_leave_minutes'].'m';
        }

        if ($attended && (int) ($meta['overtime_minutes'] ?? 0) > 0) {
            $parts[] = __('attendance::filament/pages/overview.table.columns.overtime-hours').': '.self::formatMinutesToHours((int) $meta['overtime_minutes']);
        }

        if ($attended && ($meta['is_estimated'] ?? false) === true) {
            $parts[] = __('attendance::filament/pages/overview.table.columns.estimated');
        }

        if ($parts === []) {
            return null;
        }

        return $label.' — '.implode(' · ', $parts);
    }

    protected static function clockTime(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value)->format('H:i');
    }

    /**
     * Localized status word for a register cell (display status key).
     */
    public static function statusLabel(string $status): string
    {
        if ($status === AttendanceOverviewService::STATUS_FUTURE) {
            return '';
        }

        return __('attendance::filament/pages/overview.statuses.'.str_replace('_', '-', $status));
    }

    /**
     * Compact one-glyph register cell code: a 31-column month stays on a
     * single line, while the full localized word travels in the tooltip
     * (day cells are the only consumer; totals cells keep full words).
     */
    public static function statusShortLabel(string $status): string
    {
        if ($status === '' || $status === AttendanceOverviewService::STATUS_FUTURE) {
            return '';
        }

        return (string) __('attendance::filament/pages/overview.statuses.short.'.str_replace('_', '-', $status));
    }

    /**
     * Badge/text color per status: green present, red absent, blue leave
     * (full/half), amber pending, indigo holiday, gray weekly off days.
     * Future days render blank (null color).
     */
    public static function statusColor(string $status): ?string
    {
        return match ($status) {
            AttendanceOverviewService::STATUS_PRESENT,
            AttendanceOverviewService::STATUS_WEEKEND_PRESENT,
            AttendanceOverviewService::STATUS_HOLIDAY_PRESENT    => 'success',
            AttendanceOverviewService::STATUS_ABSENT             => 'danger',
            AttendanceOverviewService::STATUS_MISSING_CHECKOUT   => 'warning',
            AttendanceOverviewService::STATUS_ON_LEAVE,
            AttendanceOverviewService::STATUS_HALF_DAY   => 'info',
            AttendanceOverviewService::STATUS_PENDING    => 'warning',
            AttendanceOverviewService::STATUS_HOLIDAY    => 'primary',
            AttendanceOverviewService::STATUS_WEEKLY_OFF => 'gray',
            AttendanceOverviewService::STATUS_FUTURE     => null,
            default                                      => null,
        };
    }
}
