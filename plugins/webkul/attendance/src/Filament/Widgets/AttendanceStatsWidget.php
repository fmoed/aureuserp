<?php

namespace Webkul\Attendance\Filament\Widgets;

use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Policies\AttendancePolicy;

class AttendanceStatsWidget extends BaseWidget
{
    use HasWidgetShield;

    protected static function getPagePermission(): ?string
    {
        return 'widget_attendance_attendance_stats_widget';
    }

    /**
     * Month-scoped attendance aggregates.
     *
     * @return array{records: int, employees: int, hours: float, open: int}
     */
    public static function monthlyStats(?Carbon $month = null): array
    {
        $month ??= Carbon::now();

        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $monthly = AttendancePolicy::scopeQuery(Attendance::query())
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to);

        $minutes = (clone $monthly)
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->toBase()
            ->select(['check_in', 'check_out', 'break_minutes'])
            ->cursor()
            ->sum(function (object $row): int {
                $gross = (int) floor(
                    Carbon::parse($row->check_in)->diffInMinutes(Carbon::parse($row->check_out))
                );

                return max(0, $gross - max(0, (int) ($row->break_minutes ?? 0)));
            });

        return [
            'records'   => (clone $monthly)->count(),
            'employees' => (clone $monthly)->distinct()->count('employee_id'),
            'hours'     => round($minutes / 60, 1),
            'open'      => (clone $monthly)->whereNull('check_out')->count(),
        ];
    }

    protected function getHeading(): ?string
    {
        return __('attendance::filament/widgets/attendance-stats.heading');
    }

    protected function getColumns(): int|array|null
    {
        // Mobile: 2x2 grid instead of 4 vertical cards; medium+ screens: single row of 4
        return [
            'default' => 2,
            'sm'      => 2,
            'md'      => 4,
            'lg'      => 4,
        ];
    }

    protected function getStats(): array
    {
        $stats = static::monthlyStats();

        $monthUrl = AttendanceResource::canViewAny()
            ? AttendanceResource::getUrl('index', ['tableFilters' => ['work_date' => ['preset' => 'this_month']]])
            : null;

        $openUrl = AttendanceResource::canViewAny()
            ? AttendanceResource::getUrl('index', ['tableFilters' => [
                'work_date'      => ['preset' => 'this_month'],
                'session_status' => ['value' => 'open'],
            ]])
            : null;

        return [
            Stat::make(__('attendance::filament/widgets/attendance-stats.stats.records'), $stats['records'])
                ->description(__('attendance::filament/widgets/attendance-stats.stats.records-description'))
                ->color('info')
                ->url($monthUrl),
            Stat::make(__('attendance::filament/widgets/attendance-stats.stats.employees'), $stats['employees'])
                ->description(__('attendance::filament/widgets/attendance-stats.stats.employees-description'))
                ->color('success')
                ->url($monthUrl),
            Stat::make(__('attendance::filament/widgets/attendance-stats.stats.hours'), $stats['hours'])
                ->description(__('attendance::filament/widgets/attendance-stats.stats.hours-description'))
                ->color('warning')
                ->url($monthUrl),
            Stat::make(__('attendance::filament/widgets/attendance-stats.stats.open'), $stats['open'])
                ->description(__('attendance::filament/widgets/attendance-stats.stats.open-description'))
                ->color('danger')
                ->url($openUrl),
        ];
    }
}
