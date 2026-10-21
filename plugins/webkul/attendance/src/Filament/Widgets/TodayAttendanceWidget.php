<?php

namespace Webkul\Attendance\Filament\Widgets;

use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Models\Attendance;

/**
 * Dashboard answer to "how is attendance doing today": distinct
 * employees who punched in, rows still open, total rows — one cheap
 * aggregate query, no absent-figure estimation (that needs the full
 * calendar-expected-days machinery and stays in the register).
 */
class TodayAttendanceWidget extends BaseWidget
{
    use HasWidgetShield;

    /**
     * Shield generates widget keys as widget_{plugin}_{basename-snake},
     * so this widget owns `widget_attendance_today_widget` (same
     * convention as AttendanceStatsWidget's key, verified pattern).
     */
    protected static function getPagePermission(): ?string
    {
        return 'widget_attendance_today_widget';
    }

    /**
     * Today's attendance aggregates from a single query.
     *
     * @return array{rows: int, present: int, open: int}
     */
    public static function todayStats(?Carbon $day = null): array
    {
        $day ??= Carbon::today();

        $rows = Attendance::query()
            ->whereDate('work_date', $day->toDateString())
            ->get(['employee_id', 'check_out']);

        return [
            'rows'    => $rows->count(),
            'present' => $rows->pluck('employee_id')->unique()->count(),
            'open'    => $rows->whereNull('check_out')->count(),
        ];
    }

    protected function getColumns(): int|array|null
    {
        return [
            'default' => 2,
            'sm'      => 2,
            'md'      => 3,
            'lg'      => 3,
        ];
    }

    protected function getHeading(): ?string
    {
        return __('attendance::filament/widgets/today-attendance.heading');
    }

    protected function getStats(): array
    {
        $stats = static::todayStats();

        return [
            Stat::make(__('attendance::filament/widgets/today-attendance.stats.present'), $stats['present'])
                ->description(__('attendance::filament/widgets/today-attendance.stats.present-description'))
                ->color('success'),
            Stat::make(__('attendance::filament/widgets/today-attendance.stats.open'), $stats['open'])
                ->description(__('attendance::filament/widgets/today-attendance.stats.open-description'))
                ->color('warning'),
            Stat::make(__('attendance::filament/widgets/today-attendance.stats.rows'), $stats['rows'])
                ->description(__('attendance::filament/widgets/today-attendance.stats.rows-description'))
                ->color('info'),
        ];
    }
}
