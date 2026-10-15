<?php

namespace Webkul\Attendance\Filament\Tables;

use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;

/**
 * Attendance register ("سجل التحضير"): one row per employee. The page
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
            ->searchable();

        $scheduleColumn = TextColumn::make('schedule')
            ->label(__('attendance::filament/pages/overview.table.columns.schedule'))
            ->state(fn (Employee $record): string => (string) ($grid[$record->getKey()]['calendar'] ?? ''))
            ->size(TextSize::Small)
            ->toggleable();

        if ($page->registerView === AttendanceOverview::REGISTER_TOTALS || $page->rangeMode === 'year') {
            // Totals sit next to the name; the long roster name goes last so
            // the eye connects each row of numbers to its employee at once.
            return self::baseTable($table, $grid, array_merge([$nameColumn], self::totalsColumns($grid), [$scheduleColumn]));
        }

        $columns = [$nameColumn, $scheduleColumn];

        foreach ($page->getGridDays() as $day) {
            $columns[] = TextColumn::make('cells.'.$day)
                ->label(Carbon::parse($day)->translatedFormat('D j'))
                ->state(fn (Employee $record): string => (string) ($grid[$record->getKey()]['cells'][$day] ?? ''))
                ->formatStateUsing(fn (mixed $state): string => self::statusLabel((string) $state))
                ->color(fn (mixed $state): ?string => self::statusColor((string) $state))
                ->size(TextSize::Small)
                ->alignCenter()
                ->placeholder('');
        }

        return self::baseTable($table, $grid, $columns);
    }

    /**
     * Per-employee totals columns (the compact "summary" view of the
     * register): visible present/absent/leave/rate plus the extra
     * pending/holidays/workdays behind the column toggle. Numeric columns
     * carry a fixed width so the summary stays dense: a full-width spread
     * leaves each value floating far from its header in RTL ("توهان").
     *
     * @param  Collection<int, array>  $grid
     * @return list<TextColumn>
     */
    protected static function totalsColumns(mixed $grid): array
    {
        $columns = [];

        foreach ([
            'present'      => __('attendance::filament/pages/overview.grid.totals.present'),
            'absent'       => __('attendance::filament/pages/overview.grid.totals.absent'),
            'on_leave'     => __('attendance::filament/pages/overview.grid.totals.on-leave'),
            'absence_rate' => __('attendance::filament/pages/overview.table.columns.rate'),
        ] as $total => $label) {
            $columns[] = TextColumn::make('totals.'.$total)
                ->label($label)
                ->state(fn (Employee $record): float => (float) ($grid[$record->getKey()]['totals'][$total] ?? 0))
                ->formatStateUsing(fn (mixed $state): string => $total === 'absence_rate'
                    ? ((float) $state).'%'
                    : (string) (int) (float) $state)
                ->size(TextSize::Small)
                ->alignCenter()
                ->width('110px');
        }

        foreach ([
            'pending'  => __('attendance::filament/pages/overview.grid.totals.pending'),
            'holidays' => __('attendance::filament/pages/overview.grid.totals.holidays'),
            'workdays' => __('attendance::filament/pages/overview.table.columns.workdays'),
        ] as $total => $label) {
            $columns[] = TextColumn::make('totals.'.$total)
                ->label($label)
                ->state(fn (Employee $record): float => (float) ($grid[$record->getKey()]['totals'][$total] ?? 0))
                ->formatStateUsing(fn (mixed $state): string => (string) (int) (float) $state)
                ->size(TextSize::Small)
                ->alignCenter()
                ->width('110px')
                ->toggleable(isToggledHiddenByDefault: true);
        }

        return $columns;
    }

    /**
     * @param  Collection<int, array>  $grid
     * @param  list<TextColumn>  $columns
     */
    protected static function baseTable(Table $table, mixed $grid, array $columns): Table
    {
        return $table
            ->query(fn (): Builder => Employee::query()
                ->whereIn('id', $grid->keys()->all())
                ->orderBy('name'))
            ->columns($columns)
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions(AttendanceOverview::PER_PAGE_OPTIONS)
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading(__('attendance::filament/pages/overview.empty.heading'))
            ->emptyStateDescription(__('attendance::filament/pages/overview.table.empty'));
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
     * Badge/text color per status: green present, red absent, blue leave
     * (full/half), amber pending, indigo holiday, gray weekly off days.
     * Future days render blank (null color).
     */
    public static function statusColor(string $status): ?string
    {
        return match ($status) {
            AttendanceOverviewService::STATUS_PRESENT   => 'success',
            AttendanceOverviewService::STATUS_ABSENT    => 'danger',
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
