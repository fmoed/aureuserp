<?php

namespace Webkul\Attendance\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarLeave;
use Webkul\TimeOff\Enums\LeaveValidationType;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveMandatoryDay;

/**
 * Present/absent engine behind the attendance overview and the absence
 * report. Read-only over existing data: punch rows decide presence, the
 * weekly calendar template decides workdays, approved leaves and public
 * holidays annotate the rest. Nothing is written here.
 *
 * Owner rules (locked): validate_two counts as on-leave; validate_one and
 * confirm are shown as pending (and count as absent — decision D4, the
 * HR role holder owns this through COUNT_PENDING_AS_ABSENT); leave types
 * with no_validation count from confirm; holidays come only from manually
 * entered calendar leaves and mandatory days. Report denominators follow
 * the HR standard: expected workdays exclude public holidays and approved
 * leave, so present + absent equals the manual absence-report figure.
 */
class AttendanceOverviewService
{
    public const MAX_RANGE_DAYS = 93;

    /**
     * Summary-shaped outputs (one row per employee: register totals, the
     * absence-report export) may span a whole leap year. Their cost grows
     * with rostered days, not with day columns, and they always run
     * aggregated and queued — unlike the interactive day grid and the
     * per-day detail export, which multiply rows by days.
     */
    public const MAX_SUMMARY_RANGE_DAYS = 366;

    /**
     * Pending (not yet approved) leave counts toward the absence numerator
     * until the HR role holder flips this — unauthorized absence stays an
     * absence until it is approved.
     */
    public const COUNT_PENDING_AS_ABSENT = true;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_ON_LEAVE_PENDING = 'on_leave_pending';

    public const STATUS_HOLIDAY = 'holiday';

    /**
     * Display-only register keys: a rostered-free day (weekend/day off)
     * gets an explicit word, while a day beyond today maps to a blank
     * cell (the uniform blank block reads as "not yet arrived"). Half
     * days and pending leave get their own keys so the register can
     * label them separately from full-day states.
     */
    public const STATUS_WEEKLY_OFF = 'weekly_off';

    public const STATUS_FUTURE = 'future';

    public const STATUS_HALF_DAY = 'half_day';

    public const STATUS_PENDING = 'pending';

    /**
     * Per-instance calendar fallback cache (company/global lookups repeat
     * for every calendar-less employee of the same company). Safe because
     * the service is resolved transiently per report run, never shared
     * across requests or queue jobs.
     *
     * @var array<string, ?Calendar>
     */
    private array $calendarCache = [];

    /**
     * Within-run memo for calendar attendance intervals. The batch engine
     * only depends on calendar + timezone + range, so employees sharing a
     * roster (the common case) hit one query instead of one each.
     *
     * @var array<string, list<array{0: Carbon, 1: Carbon}>>
     */
    private array $intervalCache = [];

    public static function rangeDays(string|Carbon $from, string|Carbon $to): int
    {
        return Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay());
    }

    /**
     * Scopes an Employee query according to the configured AttendanceSettings
     * scope ('all', 'enrolled_or_active', 'enrolled', 'recent_activity').
     *
     * - 'enrolled' matches employees with a non-empty barcode OR matching any
     *   registered writer plugin scope in WriterRegistry::employeeScopes().
     * - 'recent_activity' matches employees who have at least one record in
     *   attendance_attendances or attendance_events within the last 12 months
     *   (covering biometric, remote, and manual attendance writers).
     */
    public static function scopeEligibleEmployees(Builder $query, ?string $scope = null): Builder
    {
        $scope ??= AttendanceSettings::storedScope();

        if ($scope === AttendanceSettings::SCOPE_ALL) {
            return $query;
        }

        $applyEnrolled = static function (Builder $q): void {
            $q->where(function (Builder $inner): void {
                $inner->whereNotNull('barcode')->where('barcode', '!=', '');
            });

            foreach (WriterRegistry::employeeScopes() as $writerScope) {
                $q->orWhere(function (Builder $inner) use ($writerScope): void {
                    $writerScope($inner);
                });
            }
        };

        $applyRecentActivity = static function (Builder $q): void {
            $sinceDate = Carbon::today()->subYear()->toDateString();
            $sinceDateTime = Carbon::today()->subYear()->startOfDay()->toDateTimeString();

            $q->whereIn('id', Attendance::query()->where('work_date', '>=', $sinceDate)->select('employee_id'))
                ->orWhereIn('id', AttendanceEvent::query()->whereNotNull('employee_id')->where('punched_at', '>=', $sinceDateTime)->select('employee_id'));
        };

        return match ($scope) {
            AttendanceSettings::SCOPE_ENROLLED => $query->where(function (Builder $q) use ($applyEnrolled): void {
                $applyEnrolled($q);
            }),
            AttendanceSettings::SCOPE_RECENT_ACTIVITY => $query->where(function (Builder $q) use ($applyRecentActivity): void {
                $applyRecentActivity($q);
            }),
            AttendanceSettings::SCOPE_ENROLLED_OR_ACTIVE => $query->where(function (Builder $q) use ($applyEnrolled, $applyRecentActivity): void {
                $applyEnrolled($q);
                $q->orWhere(function (Builder $inner) use ($applyRecentActivity): void {
                    $applyRecentActivity($inner);
                });
            }),
            default => $query,
        };
    }

    /**
     * One entry per employee and workday. Days outside every employee
     * template (weekends) never produce entries; employees without any
     * resolvable calendar contribute zero workdays instead of fake absences.
     *
     * @return Collection<int, array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}>
     */
    public function daily(Collection $employees, Carbon $from, Carbon $to): Collection
    {
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        if (method_exists($employees, 'loadMissing')) {
            $employees->loadMissing('calendar');
        }

        $rows = $this->rowsByEmployeeDay($employees, $fromStr, $toStr);
        $leaves = $this->leavesByEmployee($employees, $fromStr, $toStr);
        $holidays = $this->holidaysByDate($employees, $fromStr, $toStr);

        $entries = collect();

        foreach ($employees as $employee) {
            foreach ($this->workdays($employee, $fromStr, $toStr) as $date) {
                $entries->push($this->classify($employee, $date, $rows, $leaves, $holidays));
            }
        }

        return $entries->sortBy(fn (array $entry): string => $entry['date'].'|'.$entry['name'])->values();
    }

    /**
     * Attendance register rows: one row per employee with at least one
     * rostered day in the display range, one cell per calendar day of
     * that range. Every day up to today carries an explicit display
     * status word (half days and pending leave surface as their own
     * display keys, roster-free days are `weekly_off`); days beyond
     * today render blank — the uniform blank block is self-evidently
     * the not-yet-arrived part of the month. `totals` summarize only the
     * part of the range up to today, so future days never inflate or
     * deflate the absence figures.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array{employee_id: int, name: string, calendar: ?string, cells: array<string, string>, totals: array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}}>
     */
    public function register(Collection $employees, Carbon $from, Carbon $to): Collection
    {
        if (method_exists($employees, 'loadMissing')) {
            $employees->loadMissing('calendar');
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        $today = Carbon::today();
        $todayStr = $today->toDateString();

        $entries = $from->gt($today)
            ? collect()
            : $this->daily($employees, $from, $to->gt($today) ? $today->copy() : $to);

        $grouped = $entries->groupBy('employee_id');
        $displayDates = $this->datesBetween($fromStr, $toStr, $fromStr, $toStr);

        $rows = collect();

        foreach ($employees as $employee) {
            $workdays = $this->workdays($employee, $fromStr, $toStr);

            if ($workdays === []) {
                continue;
            }

            $employeeEntries = $grouped->get($employee->getKey(), collect());
            $byDate = $employeeEntries->keyBy('date');

            $cells = [];

            foreach ($displayDates as $date) {
                $cells[$date] = $date > $todayStr
                    ? self::STATUS_FUTURE
                    : $this->displayStatus($byDate->get($date));
            }

            $calendar = $this->calendarFor($employee);

            $rows->push([
                'employee_id' => $employee->getKey(),
                'name'        => $employee->name,
                'calendar'    => $calendar?->name,
                'calendar_id' => $calendar?->id,
                'cells'       => $cells,
                'totals'      => $this->summarize($employee, $employeeEntries),
            ]);
        }

        return $rows->sortBy('name')->values();
    }

    /**
     * Yearly register: one row per employee, one cell per elapsed month of
     * the year (January through the current month for the running year —
     * future months are omitted, never blank). Each month cell carries its
     * present/absent/leave breakdown; `totals` carry the summed year.
     *
     * Kept light on purpose: every month is summarized through the same
     * batched `summary()` used elsewhere (a few indexed queries per month,
     * ~30 days of PHP each), then added up — neither the queries nor the
     * PHP working set ever span the whole year at once.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array{employee_id: int, name: string, calendar: ?string, cells: array<string, array{present: int, absent: int, on_leave: int}>, totals: array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}}>
     */
    public function registerYearly(Collection $employees, int $year): Collection
    {
        if (method_exists($employees, 'loadMissing')) {
            $employees->loadMissing('calendar');
        }

        $today = Carbon::today();
        $lastMonth = $year === $today->year ? $today->month : 12;

        $january = sprintf('%04d-01-01', $year);
        $december = sprintf('%04d-12-31', $year);

        $scoped = $employees->filter(fn (Employee $employee): bool => $this->workdays($employee, $january, $december) !== []);

        $cellsByEmployee = [];
        $totalsByEmployee = [];

        foreach ($scoped as $employee) {
            $cellsByEmployee[$employee->getKey()] = [];

            $totalsByEmployee[$employee->getKey()] = [
                'employee_id'  => $employee->getKey(),
                'name'         => $employee->name,
                'workdays'     => 0,
                'present'      => 0,
                'absent'       => 0,
                'on_leave'     => 0,
                'holidays'     => 0,
                'pending'      => 0,
                'minutes'      => 0,
                'absence_rate' => 0.0,
            ];
        }

        for ($month = 1; $month <= $lastMonth; $month++) {
            $from = Carbon::create($year, $month, 1);
            $to = $from->copy()->endOfMonth();

            if ($year === $today->year && $month === $today->month) {
                $to = $today->copy();
            }

            $monthly = $this->summary($scoped, $from, $to)->keyBy('employee_id');

            foreach ($scoped as $employee) {
                $row = $monthly->get($employee->getKey());

                if ($row === null) {
                    continue;
                }

                $cellsByEmployee[$employee->getKey()][$from->toDateString()] = [
                    'present'  => $row['present'],
                    'absent'   => $row['absent'],
                    'on_leave' => $row['on_leave'],
                ];

                foreach (['workdays', 'present', 'absent', 'on_leave', 'holidays', 'pending', 'minutes'] as $field) {
                    $totalsByEmployee[$employee->getKey()][$field] += $row[$field];
                }
            }
        }

        $rows = collect();

        foreach ($scoped as $employee) {
            $totals = $totalsByEmployee[$employee->getKey()];
            $totals['absence_rate'] = $totals['workdays'] === 0 ? 0.0 : round($totals['absent'] / $totals['workdays'] * 100, 1);

            $calendar = $this->calendarFor($employee);

            $rows->push([
                'employee_id' => $employee->getKey(),
                'name'        => $employee->name,
                'calendar'    => $calendar?->name,
                'calendar_id' => $calendar?->id,
                'cells'       => $cellsByEmployee[$employee->getKey()],
                'totals'      => $totals,
            ]);
        }

        return $rows->sortBy('name')->values();
    }

    /**
     * Display key for a single register cell: an approved half day and a
     * pending (not yet approved) leave each get their own word, and a day
     * without a classified entry is a weekly off day rather than a blank.
     */
    protected function displayStatus(?array $entry): string
    {
        if ($entry === null) {
            return self::STATUS_WEEKLY_OFF;
        }

        if ($entry['status'] === self::STATUS_ON_LEAVE && $entry['half_day']) {
            return self::STATUS_HALF_DAY;
        }

        if ($entry['status'] === self::STATUS_ON_LEAVE_PENDING) {
            return self::STATUS_PENDING;
        }

        return $entry['status'];
    }

    /**
     * Expected workdays for the detail export: `daily()` produces one row
     * per employee and workday, so the row count is the sum of workdays
     * without materializing any entry.
     */
    public function countDaily(Collection $employees, Carbon $from, Carbon $to): int
    {
        if (method_exists($employees, 'loadMissing')) {
            $employees->loadMissing('calendar');
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $total = 0;

        foreach ($employees as $employee) {
            $total += count($this->workdays($employee, $fromStr, $toStr));
        }

        return $total;
    }

    /**
     * @return Collection<int, array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}>
     */
    public function summary(Collection $employees, Carbon $from, Carbon $to): Collection
    {
        $grouped = $this->daily($employees, $from, $to)->groupBy('employee_id');

        return $employees
            ->map(fn (Employee $employee) => $this->summarize($employee, $grouped->get($employee->getKey(), collect())))
            ->values();
    }

    /**
     * Summary for a single employee (used by the absence exporter per row).
     *
     * @return array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}
     */
    public function summaryFor(Employee $employee, Carbon $from, Carbon $to): array
    {
        return $this->summarize($employee, $this->daily(collect([$employee]), $from, $to));
    }

    /**
     * HR-standard counts: `workdays` is the expected denominator (calendar
     * workdays minus public holidays minus approved leave), so
     * present + absent mirrors the manual absence report, and the rate is
     * absent ÷ expected. Pending leave stays in the denominator (not yet
     * approved) and joins the numerator when COUNT_PENDING_AS_ABSENT.
     *
     * @param  Collection<int, array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}>  $entries
     * @return array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}
     */
    protected function summarize(Employee $employee, Collection $entries): array
    {
        $present = $entries->where('status', self::STATUS_PRESENT)->count();
        $onLeave = $entries->where('status', self::STATUS_ON_LEAVE)->count();
        $holidays = $entries->where('status', self::STATUS_HOLIDAY)->count();
        $pending = $entries->where('status', self::STATUS_ON_LEAVE_PENDING)->count();

        $absent = $entries->where('status', self::STATUS_ABSENT)->count()
            + (self::COUNT_PENDING_AS_ABSENT ? $pending : 0);

        $workdays = max(0, $entries->count() - $holidays - $onLeave);

        return [
            'employee_id'  => $employee->getKey(),
            'name'         => $employee->name,
            'workdays'     => $workdays,
            'present'      => $present,
            'absent'       => $absent,
            'on_leave'     => $onLeave,
            'holidays'     => $holidays,
            'pending'      => $pending,
            'minutes'      => $entries->sum(fn (array $entry): int => $entry['minutes'] ?? 0),
            'absence_rate' => $workdays === 0 ? 0.0 : round($absent / $workdays * 100, 1),
        ];
    }

    /**
     * @return list<string> workday dates (Y-m-d) in the employee time zone.
     */
    protected function workdays(Employee $employee, string $from, string $to): array
    {
        $calendar = $this->calendarFor($employee);

        if (! $calendar) {
            return [];
        }

        $timezone = $employee->time_zone ?: config('app.timezone');

        $key = $calendar->id.'|'.$timezone.'|'.$from.'|'.$to;

        if (! array_key_exists($key, $this->intervalCache)) {
            $this->intervalCache[$key] = collect($calendar->getAttendanceIntervalsBatch(
                Carbon::parse($from.' 00:00:00', 'UTC'),
                Carbon::parse($to.' 23:59:59', 'UTC'),
                timezone: $timezone,
            ))->first() ?? [];
        }

        $intervals = $this->intervalCache[$key];

        return collect($intervals)
            ->map(fn (array $interval): string => $interval[0]->copy()->setTimezone($timezone)->toDateString())
            ->filter(fn (string $date): bool => $date >= $from && $date <= $to)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Schedule resolution: the employee calendar first, then the company
     * calendar, then a global (company-less) calendar shared by everyone.
     * Only calendars with configured attendance intervals (working hours)
     * are treated as operable schedules, preventing empty calendar shells
     * from wiping out workdays and hiding employees.
     */
    protected function calendarFor(Employee $employee): ?Calendar
    {
        if ($employee->calendar && $this->calendarHasAttendance($employee->calendar)) {
            return $employee->calendar;
        }

        if ($employee->company_id !== null) {
            $key = "company:{$employee->company_id}";

            if (! array_key_exists($key, $this->calendarCache)) {
                $this->calendarCache[$key] = Calendar::query()
                    ->where('company_id', $employee->company_id)
                    ->where('is_active', true)
                    ->whereHas('attendance')
                    ->orderBy('id')
                    ->first();
            }

            if ($this->calendarCache[$key]) {
                return $this->calendarCache[$key];
            }
        }

        if (! array_key_exists('global', $this->calendarCache)) {
            $this->calendarCache['global'] = Calendar::query()
                ->whereNull('company_id')
                ->where('is_active', true)
                ->whereHas('attendance')
                ->orderBy('id')
                ->first();
        }

        if ($this->calendarCache['global']) {
            return $this->calendarCache['global'];
        }

        return $employee->calendar
            ?? ($employee->company_id !== null
                ? Calendar::query()->where('company_id', $employee->company_id)->where('is_active', true)->orderBy('id')->first()
                : null)
            ?? Calendar::query()->whereNull('company_id')->where('is_active', true)->orderBy('id')->first();
    }

    protected function calendarHasAttendance(Calendar $calendar): bool
    {
        return $calendar->relationLoaded('attendance')
            ? $calendar->attendance->isNotEmpty()
            : $calendar->attendance()->exists();
    }

    /**
     * Model keys without requiring an Eloquent collection (the exporter and
     * single-employee summaries pass plain support collections).
     *
     * @return list<int>
     */
    protected function employeeIds(Collection $employees): array
    {
        return $employees->map(fn (Employee $employee): int => $employee->getKey())->values()->all();
    }

    /**
     * @return Collection<string, Collection<int, Attendance>>
     */
    protected function rowsByEmployeeDay(Collection $employees, string $from, string $to): Collection
    {
        return Attendance::query()
            ->whereIn('employee_id', $this->employeeIds($employees))
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->with('employee')
            ->get()
            ->groupBy(fn (Attendance $row): string => $row->employee_id.'|'.$row->work_date->format('Y-m-d'));
    }

    /**
     * @return Collection<int, Collection<int, Leave>>
     */
    protected function leavesByEmployee(Collection $employees, string $from, string $to): Collection
    {
        if (! Package::isPluginInstalled('time-off') || ! Schema::hasTable('time_off_leaves')) {
            return collect();
        }

        $companyIds = $employees->pluck('company_id')->filter()->unique()->values()->all();

        return Leave::query()
            ->whereIn('employee_id', $this->employeeIds($employees))
            ->where(fn ($query) => $query->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            ->whereDate('date_to', '>=', $from)
            ->whereDate('date_from', '<=', $to)
            ->where('state', '!=', State::REFUSE->value)
            ->with('holidayStatus')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * Holidays are employee-facing public holidays only: `time_type = 'leave'`
     * (what the public-holiday form writes) and no bound resource, mirroring
     * `Calendar::getLeaveIntervalsBatch()`. Resource-bound or `other` rows —
     * e.g. manufacturing work-order downtime — are calendar capacity, not
     * days off for everyone.
     *
     * @return Collection<string, string> date (Y-m-d) => holiday label.
     */
    protected function holidaysByDate(Collection $employees, string $from, string $to): Collection
    {
        $calendarIds = $employees->pluck('calendar_id')->filter()->unique()->values()->all();
        $companyIds = $employees->pluck('company_id')->filter()->unique()->values()->all();

        $public = CalendarLeave::query()
            ->where('time_type', 'leave')
            ->whereNull('resource_id')
            ->whereDate('date_to', '>=', $from)
            ->whereDate('date_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('calendar_id')->orWhereIn('calendar_id', $calendarIds))
            ->where(fn ($query) => $query->whereNull('company_id')->orWhereIn('company_id', $companyIds))
            ->get();

        $mandatory = (Package::isPluginInstalled('time-off') && Schema::hasTable('time_off_leave_mandatory_days'))
            ? LeaveMandatoryDay::query()
                ->where('end_date', '>=', $from)
                ->where('start_date', '<=', $to)
                ->where(fn ($query) => $query->whereNull('company_id')->orWhereIn('company_id', $companyIds))
                ->get()
            : collect();

        $map = collect();

        foreach ($public as $holiday) {
            foreach ($this->datesBetween($holiday->date_from, $holiday->date_to, $from, $to) as $date) {
                $map->put($date, $map->get($date) ?? $holiday->name);
            }
        }

        foreach ($mandatory as $day) {
            foreach ($this->datesBetween($day->start_date, $day->end_date, $from, $to) as $date) {
                $map->put($date, $map->get($date) ?? $day->name);
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    protected function datesBetween(mixed $start, mixed $end, string $from, string $to): array
    {
        if ($start === null || $end === null) {
            return [];
        }

        $dates = [];
        $current = Carbon::parse($start)->startOfDay();
        $stop = Carbon::parse($end)->startOfDay();

        while ($current->lte($stop)) {
            $date = $current->toDateString();

            if ($date >= $from && $date <= $to) {
                $dates[] = $date;
            }

            $current->addDay();
        }

        return $dates;
    }

    /**
     * @param  Collection<string, Collection<int, Attendance>>  $rows
     * @param  Collection<int, Collection<int, Leave>>  $leaves
     * @param  Collection<string, string>  $holidays
     * @return array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}
     */
    protected function classify(Employee $employee, string $date, Collection $rows, Collection $leaves, Collection $holidays): array
    {
        $dayRows = $rows->get($employee->getKey().'|'.$date, collect());
        $leave = $this->leaveOnDay($leaves->get($employee->getKey(), collect()), $date);
        $timezone = $employee->time_zone ?: config('app.timezone');

        $checkIn = null;
        $checkOut = null;
        $minutes = null;

        if ($dayRows->isNotEmpty()) {
            $rawIn = $dayRows->map(fn (Attendance $row): ?string => $row->getRawOriginal('check_in'))->filter()->min();
            $rawOut = $dayRows->map(fn (Attendance $row): ?string => $row->getRawOriginal('check_out'))->filter()->max();

            $checkIn = $rawIn ? Carbon::parse($rawIn, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s') : null;
            $checkOut = $rawOut ? Carbon::parse($rawOut, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s') : null;
            $minutes = $dayRows->contains(fn (Attendance $row): bool => $row->worked_minutes !== null)
                ? $dayRows->sum(fn (Attendance $row): int => $row->worked_minutes ?? 0)
                : null;
        }

        $leaveLabel = $leave?->holidayStatus?->name;
        $halfDay = (bool) $leave?->request_unit_half;

        if ($dayRows->isNotEmpty()) {
            $status = self::STATUS_PRESENT;
        } elseif ($leave && $this->leaveStatus($leave) === self::STATUS_ON_LEAVE) {
            $status = self::STATUS_ON_LEAVE;
        } elseif ($holidays->has($date)) {
            $status = self::STATUS_HOLIDAY;
            $leaveLabel = $holidays->get($date);
            $halfDay = false;
        } elseif ($leave) {
            $status = self::STATUS_ON_LEAVE_PENDING;
        } else {
            $status = self::STATUS_ABSENT;
        }

        return [
            'employee_id' => $employee->getKey(),
            'name'        => $employee->name,
            'date'        => $date,
            'status'      => $status,
            'leave_label' => $leaveLabel,
            'half_day'    => $halfDay,
            'check_in'    => $checkIn,
            'check_out'   => $checkOut,
            'minutes'     => $minutes,
        ];
    }

    /**
     * @param  Collection<int, Leave>  $leaves
     */
    protected function leaveOnDay(Collection $leaves, string $date): ?Leave
    {
        return $leaves->first(function (Leave $leave) use ($date): bool {
            if ($leave->date_from === null || $leave->date_to === null) {
                return false;
            }

            $start = $leave->date_from instanceof Carbon ? $leave->date_from->toDateString() : Carbon::parse($leave->date_from)->toDateString();
            $stop = $leave->date_to instanceof Carbon ? $leave->date_to->toDateString() : Carbon::parse($leave->date_to)->toDateString();

            return $start <= $date && $date <= $stop;
        });
    }

    protected function leaveStatus(Leave $leave): string
    {
        if ($leave->state === State::VALIDATE_TWO) {
            return self::STATUS_ON_LEAVE;
        }

        if ($leave->holidayStatus?->leave_validation_type === LeaveValidationType::NO_VALIDATION) {
            return self::STATUS_ON_LEAVE;
        }

        return self::STATUS_ON_LEAVE_PENDING;
    }
}
