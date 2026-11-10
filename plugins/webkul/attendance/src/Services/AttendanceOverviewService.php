<?php

namespace Webkul\Attendance\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Webkul\Attendance\Enums\HolidayPolicy;
use Webkul\Attendance\Enums\MandatoryDaysPolicy;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Scopes\CompanyScope;
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

    public const STATUS_MISSING_CHECKOUT = 'missing_checkout';

    public const STATUS_WEEKEND_PRESENT = 'weekend_present';

    public const STATUS_HOLIDAY_PRESENT = 'holiday_present';

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

    /**
     * Default shift id per company (null key = the global shift). The
     * default is asked for once per calendar row, once more for its
     * inherited count and again inside the shift filter; the lookup only
     * changes when a company's shifts change, which is a configuration
     * action rather than a per-row event.
     *
     * @var array<string, int|null>
     */
    private static array $defaultCalendarIdCache = [];

    /**
     * Per-run cache of the pending-leave policy so summaries do not hit the
     * settings repository once per employee.
     */
    private ?bool $countPendingAsAbsent = null;

    private ?bool $countUnpaidLeaveAsAbsent = null;

    private ?ShiftWindowResolver $resolverInstance = null;

    protected function resolver(): ShiftWindowResolver
    {
        return $this->resolverInstance ??= new ShiftWindowResolver($this);
    }

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

        $query->where(function (Builder $q): void {
            $q->where('is_active', true)
                ->orWhereIn('id', Attendance::query()->select('employee_id'))
                ->orWhereIn('id', AttendanceEvent::query()->whereNotNull('employee_id')->select('employee_id'));
        });

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
     * Restricts an employee query to the given shift(s). Employee-bound
     * calendars match directly; calendar-less employees are included when
     * the selected shift is their company default (or the global shift for
     * companies that have no configured default of their own), mirroring the
     * roster fallback used by calendarFor().
     *
     * @param  int|array<int, int|string>  $calendarIds
     */
    public static function scopeByCalendar(Builder $query, int|array $calendarIds): Builder
    {
        $ids = collect((array) $calendarIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return $query;
        }

        $selected = Calendar::withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $ids)
            ->get(['id', 'company_id']);

        if ($selected->isEmpty()) {
            return $query->whereIn($query->getModel()->qualifyColumn('calendar_id'), $ids);
        }

        $calendarColumn = $query->getModel()->qualifyColumn('calendar_id');
        $companyColumn = $query->getModel()->qualifyColumn('company_id');

        $defaultCompanyIds = [];

        foreach ($selected->whereNotNull('company_id') as $calendar) {
            if (static::defaultCalendarId((int) $calendar->company_id) === (int) $calendar->id) {
                $defaultCompanyIds[] = (int) $calendar->company_id;
            }
        }

        $globalSelected = $selected->contains(fn (Calendar $calendar): bool => $calendar->company_id === null);

        $companiesWithOwnDefault = $globalSelected
            ? Calendar::withoutGlobalScope(CompanyScope::class)
                ->whereNotNull('company_id')
                ->where('is_active', true)
                ->whereHas('attendance')
                ->select('company_id')
                ->distinct()
                ->pluck('company_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        return $query->where(function (Builder $inner) use ($ids, $calendarColumn, $companyColumn, $defaultCompanyIds, $globalSelected, $companiesWithOwnDefault): void {
            $inner->whereIn($calendarColumn, $ids);

            if ($defaultCompanyIds !== []) {
                $inner->orWhere(function (Builder $fallback) use ($calendarColumn, $companyColumn, $defaultCompanyIds): void {
                    $fallback->whereNull($calendarColumn)->whereIn($companyColumn, $defaultCompanyIds);
                });
            }

            if ($globalSelected) {
                $inner->orWhere(function (Builder $fallback) use ($calendarColumn, $companyColumn, $companiesWithOwnDefault): void {
                    $fallback->whereNull($calendarColumn)
                        ->where(function (Builder $companyScope) use ($companyColumn, $companiesWithOwnDefault): void {
                            $companyScope->whereNull($companyColumn);

                            if ($companiesWithOwnDefault !== []) {
                                $companyScope->orWhereNotIn($companyColumn, $companiesWithOwnDefault);
                            }
                        });
                });
            }
        });
    }

    /**
     * Whether this shift is the effective default of its company: the first
     * active calendar with working hours for that company, or the first such
     * global calendar for companies without one of their own. Mirrors the
     * fallback in calendarFor().
     */
    public static function isDefaultCalendar(Calendar $calendar): bool
    {
        $companyId = $calendar->company_id === null ? null : (int) $calendar->company_id;
        $defaultId = static::defaultCalendarId($companyId);

        return $defaultId !== null && $defaultId === (int) $calendar->getKey();
    }

    /**
     * First active calendar with working hours for a company, or the first
     * global one when no company is given. Memoized for the request because
     * the shift table, its inherited count and the shift filter all ask the
     * same question.
     */
    protected static function defaultCalendarId(?int $companyId): ?int
    {
        $key = $companyId === null ? 'global' : 'company:'.$companyId;

        if (! array_key_exists($key, static::$defaultCalendarIdCache)) {
            $query = Calendar::withoutGlobalScope(CompanyScope::class)
                ->where('is_active', true)
                ->whereHas('attendance')
                ->orderBy('id');

            $id = $companyId === null
                ? $query->whereNull('company_id')->value('id')
                : $query->where('company_id', $companyId)->value('id');

            static::$defaultCalendarIdCache[$key] = $id === null ? null : (int) $id;
        }

        return static::$defaultCalendarIdCache[$key];
    }

    protected static array $inheritedCountsCache = [];

    /**
     * Eligible employees with no shift assigned who fall back to this
     * calendar as their company (or global) default.
     */
    public static function inheritedEmployeesCount(Calendar $calendar): int
    {
        if (! static::isDefaultCalendar($calendar)) {
            return 0;
        }

        $cacheKey = $calendar->getKey().':'.($calendar->company_id ?? 'null');

        if (array_key_exists($cacheKey, static::$inheritedCountsCache)) {
            return static::$inheritedCountsCache[$cacheKey];
        }

        $query = static::scopeEligibleEmployees(Employee::query())
            ->whereNull('calendar_id');

        if ($calendar->company_id !== null) {
            return static::$inheritedCountsCache[$cacheKey] = $query->where('company_id', $calendar->company_id)->count();
        }

        $companiesWithOwnDefault = Calendar::withoutGlobalScope(CompanyScope::class)
            ->whereNotNull('company_id')
            ->where('is_active', true)
            ->whereHas('attendance')
            ->select('company_id')
            ->distinct()
            ->pluck('company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return static::$inheritedCountsCache[$cacheKey] = $query
            ->where(function (Builder $inner) use ($companiesWithOwnDefault): void {
                $inner->whereNull('company_id');

                if ($companiesWithOwnDefault !== []) {
                    $inner->orWhereNotIn('company_id', $companiesWithOwnDefault);
                }
            })
            ->count();
    }

    /**
     * Eligible employees reduced to the caller's ownership scope. Every
     * picker and register query goes through here so a scoped profile can
     * never list the whole company roster.
     */
    public static function eligibleEmployeeQuery(): Builder
    {
        return AttendancePolicy::scopeEmployeeQuery(static::scopeEligibleEmployees(Employee::query()));
    }

    /**
     * Bounded employee picker options (first :limit matches), never a full
     * pluck of the roster on every Livewire request.
     *
     * @return array<int, string>
     */
    public static function searchEligibleEmployees(?string $search = null, int $limit = 50): array
    {
        return static::eligibleEmployeeQuery()
            ->when(filled($search), function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->limit($limit)
            ->pluck('name', 'id')
            ->all();
    }

    public static function employeeOptionLabel(mixed $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        return static::eligibleEmployeeQuery()->whereKey($id)->value('name');
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    public static function employeeOptionLabels(array $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return static::eligibleEmployeeQuery()
            ->whereKey($ids)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Restricts a query to one or more departments and every descendant
     * department. Departments are stored as a materialized path
     * (`parent_path = '/1/3/'`), so a single LIKE covers the whole subtree.
     *
     * @param  int|array<int, int|string>  $departmentIds
     */
    public static function scopeByDepartment(Builder $query, int|array $departmentIds, string $column = 'department_id'): Builder
    {
        $ids = collect((array) $departmentIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return $query;
        }

        $departments = Department::withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', $ids)
            ->get(['id', 'parent_path']);

        $allIds = $ids;

        if ($departments->isNotEmpty()) {
            $prefixes = $departments
                ->map(fn (Department $department): string => ($department->parent_path ?? '/').$department->id.'/')
                ->all();

            $descendantIds = Department::withoutGlobalScope(CompanyScope::class)
                ->where(function (Builder $inner) use ($prefixes): void {
                    foreach ($prefixes as $prefix) {
                        $inner->orWhere('parent_path', 'like', $prefix.'%');
                    }
                })
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $allIds = array_values(array_unique([...$ids, ...$descendantIds]));
        }

        return $query->whereIn($query->getModel()->qualifyColumn($column), $allIds);
    }

    /**
     * Department picker options labelled by their full hierarchy path.
     *
     * @return array<int, string>
     */
    public static function departmentOptions(?int $companyId = null): array
    {
        return Department::query()
            ->when($companyId, fn (Builder $query, int $companyId) => $query->where('company_id', $companyId))
            ->orderBy('complete_name')
            ->pluck('complete_name', 'id')
            ->map(fn (mixed $name, mixed $id): string => filled($name) ? (string) $name : (string) $id)
            ->all();
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

        $rowDatesByEmployee = $rows->keys()
            ->map(fn (string $key): array => explode('|', $key, 2))
            ->groupBy(0)
            ->map(fn (Collection $pairs): array => $pairs->pluck(1)->unique()->values()->all());

        $mandatoryPolicy = AttendanceSettings::mandatoryDaysPolicy();

        $entries = collect();

        foreach ($employees as $employee) {
            $workdays = $this->workdays($employee, $fromStr, $toStr);
            $workdaySet = array_flip($workdays);

            $mandatoryWorkdays = $mandatoryPolicy === MandatoryDaysPolicy::TreatAsMandatoryWorkday
                ? $this->applicableMandatoryWorkdayDates($employee, $holidays)
                : [];

            $dates = collect($workdays)
                ->merge($rowDatesByEmployee->get((string) $employee->getKey(), []))
                ->merge($mandatoryWorkdays)
                ->filter(fn (string $date): bool => $date >= $fromStr && $date <= $toStr)
                ->unique()
                ->sort()
                ->values();

            foreach ($dates as $date) {
                $entry = $this->classify($employee, $date, $rows, $leaves, $holidays, isset($workdaySet[$date]));

                if ($entry['status'] === self::STATUS_WEEKLY_OFF) {
                    continue;
                }

                $entries->push($entry);
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
     * @return Collection<int, array{employee_id: int, name: string, calendar: ?string, calendar_id: ?int, cells: array<string, string>, meta: array<string, array{check_in: ?string, check_out: ?string, minutes: ?int, leave_label: ?string}>, totals: array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}}>
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

            $employeeEntries = $grouped->get($employee->getKey(), collect());

            if ($workdays === [] && $employeeEntries->isEmpty()) {
                continue;
            }

            $byDate = $employeeEntries->keyBy('date');

            $meta = $employeeEntries->mapWithKeys(fn (array $entry): array => [
                $entry['date'] => [
                    'check_in'            => $entry['check_in'],
                    'check_out'           => $entry['check_out'],
                    'minutes'             => $entry['minutes'],
                    'scheduled_minutes'   => $entry['scheduled_minutes'] ?? null,
                    'break_minutes'       => $entry['break_minutes'] ?? null,
                    'overtime_minutes'    => $entry['overtime_minutes'] ?? null,
                    'late_minutes'        => $entry['late_minutes'] ?? null,
                    'early_leave_minutes' => $entry['early_leave_minutes'] ?? null,
                    'variance_minutes'    => $entry['variance_minutes'] ?? null,
                    'is_estimated'        => $entry['is_estimated'] ?? false,
                    'leave_label'         => $entry['leave_label'],
                ],
            ])->all();

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
                'meta'        => $meta,
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
     * The year is classified in one `daily()` pass (four batched queries)
     * and grouped by month in memory — not re-summarized month by month.
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

        $employeesWithRows = Attendance::query()
            ->whereIn('employee_id', $employees->map(fn (Employee $employee): int => $employee->getKey())->all())
            ->whereYear('work_date', $year)
            ->distinct()
            ->pluck('employee_id')
            ->flip();

        $scoped = $employees->filter(fn (Employee $employee): bool => $this->workdays($employee, $january, $december) !== []
            || $employeesWithRows->has($employee->getKey()));

        $monthStarts = [];

        for ($month = 1; $month <= $lastMonth; $month++) {
            $monthStarts[] = sprintf('%04d-%02d-01', $year, $month);
        }

        $cellsByEmployee = [];
        $totalsByEmployee = [];

        foreach ($scoped as $employee) {
            $cellsByEmployee[$employee->getKey()] = array_fill_keys($monthStarts, [
                'present'           => 0,
                'absent'            => 0,
                'on_leave'          => 0,
                'minutes'           => 0,
                'scheduled_minutes' => 0,
                'overtime_minutes'  => 0,
            ]);

            $totalsByEmployee[$employee->getKey()] = [
                'employee_id'           => $employee->getKey(),
                'name'                  => $employee->name,
                'workdays'              => 0,
                'present'               => 0,
                'absent'                => 0,
                'on_leave'              => 0,
                'holidays'              => 0,
                'pending'               => 0,
                'minutes'               => 0,
                'scheduled_minutes'     => 0,
                'regular_minutes'       => 0,
                'overtime_minutes'      => 0,
                'break_minutes'         => 0,
                'late_minutes'          => 0,
                'late_count'            => 0,
                'early_leave_minutes'   => 0,
                'early_leave_count'     => 0,
                'variance_minutes'      => 0,
                'missing_checkouts'     => 0,
                'weekend_worked_days'   => 0,
                'absence_rate'          => 0.0,
                'hours_compliance_rate' => 0.0,
            ];
        }

        if ($scoped->isNotEmpty()) {
            $rangeEnd = $year === $today->year
                ? $today->copy()->startOfDay()
                : Carbon::create($year, 12, 31)->endOfDay();

            $grouped = $this->daily($scoped, Carbon::create($year, 1, 1), $rangeEnd)
                ->groupBy('employee_id');

            foreach ($scoped as $employee) {
                $monthly = $grouped->get($employee->getKey(), collect())
                    ->groupBy(fn (array $entry): string => substr($entry['date'], 0, 7).'-01');

                foreach ($monthly as $monthStart => $monthEntries) {
                    $row = $this->summarize($employee, $monthEntries);

                    $cellsByEmployee[$employee->getKey()][$monthStart] = [
                        'present'           => $row['present'],
                        'absent'            => $row['absent'],
                        'on_leave'          => $row['on_leave'],
                        'minutes'           => $row['minutes'],
                        'scheduled_minutes' => $row['scheduled_minutes'],
                        'overtime_minutes'  => $row['overtime_minutes'],
                    ];

                    foreach ([
                        'workdays',
                        'present',
                        'absent',
                        'on_leave',
                        'holidays',
                        'pending',
                        'minutes',
                        'scheduled_minutes',
                        'regular_minutes',
                        'overtime_minutes',
                        'break_minutes',
                        'late_minutes',
                        'late_count',
                        'early_leave_minutes',
                        'early_leave_count',
                        'variance_minutes',
                        'missing_checkouts',
                        'weekend_worked_days',
                    ] as $field) {
                        $totalsByEmployee[$employee->getKey()][$field] += $row[$field];
                    }
                }
            }
        }

        $rows = collect();

        foreach ($scoped as $employee) {
            $totals = $totalsByEmployee[$employee->getKey()];
            $totals['absence_rate'] = $totals['workdays'] === 0 ? 0.0 : round($totals['absent'] / $totals['workdays'] * 100, 1);
            $totals['hours_compliance_rate'] = $totals['scheduled_minutes'] === 0
                ? 0.0
                : min(100.0, round($totals['regular_minutes'] / $totals['scheduled_minutes'] * 100, 1));

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

        $rowDates = $this->rowDatesByEmployee($employees, $fromStr, $toStr);

        $total = 0;

        foreach ($employees as $employee) {
            $dates = collect($this->workdays($employee, $fromStr, $toStr))
                ->merge($rowDates->get((string) $employee->getKey(), []))
                ->unique();

            $total += $dates->count();
        }

        return $total;
    }

    /**
     * @return Collection<string, list<string>>
     */
    protected function rowDatesByEmployee(Collection $employees, string $from, string $to): Collection
    {
        return Attendance::query()
            ->whereIn('employee_id', $this->employeeIds($employees))
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->select(['employee_id', 'work_date'])
            ->distinct()
            ->get()
            ->groupBy(fn (Attendance $row): string => (string) $row->employee_id)
            ->map(fn (Collection $rows): array => $rows
                ->map(fn (Attendance $row): string => $row->work_date->format('Y-m-d'))
                ->unique()
                ->values()
                ->all());
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
     * Employee ids that have at least one rostered workday in the range.
     * Used by the detail export to size its rows without building the full
     * day matrix first (the CSV job builds it per chunk).
     *
     * @param  Collection<int, Employee>  $employees
     * @return list<int>
     */
    public function employeeIdsWithWorkdays(Collection $employees, Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $rowDates = $this->rowDatesByEmployee($employees, $fromDate, $toDate);

        return $employees
            ->filter(fn (Employee $employee): bool => $this->workdays($employee, $fromDate, $toDate) !== []
                || ($rowDates->get((string) $employee->getKey(), []) !== []))
            ->map(fn (Employee $employee): int => $employee->getKey())
            ->values()
            ->all();
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
    protected function countPendingAsAbsent(): bool
    {
        return $this->countPendingAsAbsent ??= AttendanceSettings::countPendingLeaveAsAbsent();
    }

    protected function countUnpaidLeaveAsAbsent(): bool
    {
        return $this->countUnpaidLeaveAsAbsent ??= AttendanceSettings::countUnpaidLeaveAsAbsent();
    }

    protected function summarize(Employee $employee, Collection $entries): array
    {
        $weight = fn (float $value): int|float => abs($value - round($value)) < 0.0001
            ? (int) round($value)
            : round($value, 1);

        $present = $weight($entries->sum(fn (array $entry): float => (float) ($entry['present_weight'] ?? 0)));
        $onLeave = $weight($entries->sum(fn (array $entry): float => (float) ($entry['leave_weight'] ?? 0)));
        $holidays = $entries->where('status', self::STATUS_HOLIDAY)->count();
        $pending = $entries->where('status', self::STATUS_ON_LEAVE_PENDING)->count();
        $missingCheckouts = $entries->where('status', self::STATUS_MISSING_CHECKOUT)->count();
        $weekendPresent = $entries->where('status', self::STATUS_WEEKEND_PRESENT)->count();
        $holidayPresent = $entries->where('status', self::STATUS_HOLIDAY_PRESENT)->count();

        $absent = $entries->sum(fn (array $entry): float => (float) ($entry['absent_weight'] ?? 0));
        $unpaidLeave = $entries
            ->filter(fn (array $entry): bool => (bool) ($entry['leave_unpaid'] ?? false))
            ->sum(fn (array $entry): float => (float) ($entry['leave_weight'] ?? 0));

        if ($this->countUnpaidLeaveAsAbsent()) {
            $absent += $unpaidLeave;
            $onLeaveForDenominator = (float) $onLeave - $unpaidLeave;
        } else {
            $onLeaveForDenominator = (float) $onLeave;
        }

        $absent = $weight($absent);

        $workdays = $weight(max(
            0.0,
            $entries->count() - $holidays - $onLeaveForDenominator - $weekendPresent - $holidayPresent,
        ));

        $scheduled = (int) $entries->sum(fn (array $entry): int => (int) ($entry['scheduled_minutes'] ?? 0));
        $regular = (int) $entries->sum(fn (array $entry): int => (int) ($entry['regular_minutes'] ?? 0));

        $paidLeave = $entries
            ->filter(fn (array $entry): bool => ! (bool) ($entry['leave_unpaid'] ?? false))
            ->sum(fn (array $entry): float => (float) ($entry['leave_weight'] ?? 0));

        $paidLeaveMinutes = $entries
            ->filter(fn (array $entry): bool => ! (bool) ($entry['leave_unpaid'] ?? false))
            ->sum(fn (array $entry): int => (int) ($entry['leave_minutes'] ?? 0));

        $unpaidLeaveMinutes = $entries
            ->filter(fn (array $entry): bool => (bool) ($entry['leave_unpaid'] ?? false))
            ->sum(fn (array $entry): int => (int) ($entry['leave_minutes'] ?? 0));

        return [
            'employee_id'           => $employee->getKey(),
            'name'                  => $employee->name,
            'workdays'              => $workdays,
            'present'               => $present,
            'half_days'             => $entries->where('status', self::STATUS_HALF_DAY)->count(),
            'absent'                => $absent,
            'on_leave'              => $onLeave,
            'paid_leave_days'       => $weight((float) $paidLeave),
            'unpaid_leave_days'     => $weight($unpaidLeave),
            'paid_leave_minutes'    => (int) $paidLeaveMinutes,
            'unpaid_leave_minutes'  => (int) $unpaidLeaveMinutes,
            'holidays'              => $holidays,
            'pending'               => $pending,
            'missing_checkouts'     => $missingCheckouts,
            'weekend_worked_days'   => $weekendPresent,
            'holiday_worked_days'   => $holidayPresent,
            'scheduled_minutes'     => $scheduled,
            'minutes'               => (int) $entries->sum(fn (array $entry): int => (int) ($entry['minutes'] ?? 0)),
            'regular_minutes'       => $regular,
            'overtime_minutes'      => (int) $entries->sum(fn (array $entry): int => (int) ($entry['overtime_minutes'] ?? 0)),
            'break_minutes'         => (int) $entries->sum(fn (array $entry): int => (int) ($entry['break_minutes'] ?? 0)),
            'late_minutes'          => (int) $entries->sum(fn (array $entry): int => (int) ($entry['late_minutes'] ?? 0)),
            'late_count'            => (int) $entries->sum(fn (array $entry): int => (int) ($entry['late_count'] ?? 0)),
            'early_leave_minutes'   => (int) $entries->sum(fn (array $entry): int => (int) ($entry['early_leave_minutes'] ?? 0)),
            'early_leave_count'     => (int) $entries->sum(fn (array $entry): int => (int) ($entry['early_leave_count'] ?? 0)),
            'variance_minutes'      => (int) $entries->sum(fn (array $entry): int => (int) ($entry['variance_minutes'] ?? 0)),
            'absence_rate'          => $workdays === 0 ? 0.0 : round($absent / $workdays * 100, 1),
            'hours_compliance_rate' => $scheduled === 0 ? 0.0 : min(100.0, round($regular / $scheduled * 100, 1)),
        ];
    }

    /**
     * @return list<string> workday dates (Y-m-d) in the employee time zone.
     */
    protected function workdays(Employee $employee, string $from, string $to): array
    {
        if (! $employee->is_active && ! Attendance::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])->exists()) {
            return [];
        }

        $effectiveTo = $to;

        if (! empty($employee->departure_date)) {
            $departureString = Carbon::parse($employee->departure_date)->toDateString();

            if ($departureString < $effectiveTo) {
                $effectiveTo = $departureString;
            }
        }

        if ($effectiveTo < $from) {
            return [];
        }

        $calendar = $this->calendarFor($employee);

        if (! $calendar) {
            return [];
        }

        // Roster dates are the calendar template's own wall-clock dates, so
        // the intervals are generated in UTC. Applying the employee zone
        // here shifted evening/night shifts into an adjacent calendar day.
        $key = $calendar->id.'|'.$from.'|'.$to;

        if (! array_key_exists($key, $this->intervalCache)) {
            $this->intervalCache[$key] = collect($calendar->getAttendanceIntervalsBatch(
                Carbon::parse($from.' 00:00:00', 'UTC'),
                Carbon::parse($to.' 23:59:59', 'UTC'),
                timezone: 'UTC',
            ))->first() ?? [];
        }

        $intervals = $this->intervalCache[$key];

        return collect($intervals)
            ->map(fn (array $interval): string => $interval[0]->copy()->utc()->toDateString())
            ->filter(fn (string $date): bool => $date >= $from && $date <= $effectiveTo)
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
    public function calendarFor(Employee $employee): ?Calendar
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
            ->where('state', '!=', State::REFUSE->value)
            ->where(function ($query) use ($from): void {
                $query->whereDate('date_to', '>=', $from)
                    ->orWhere(fn ($query) => $query->whereNull('date_to')->whereDate('date_from', '>=', $from));
            })
            ->whereDate('date_from', '<=', $to)
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
     * Each date maps to the full set of candidate entries (with their shift
     * and company metadata) so classify() can match only the ones that
     * actually apply to the employee: employees inheriting the company
     * default shift now see its holidays, and a shift-specific holiday never
     * leaks to an employee rostered on another shift.
     *
     * @return Collection<string, Collection<int, array{name: string, calendar_id: ?int, company_id: ?int}>>
     */
    protected function holidaysByDate(Collection $employees, string $from, string $to): Collection
    {
        $calendarIds = $employees
            ->map(fn (Employee $employee): ?int => $this->calendarFor($employee)?->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

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
                $map->put($date, $map->get($date, collect())->push([
                    'name'        => $holiday->name,
                    'calendar_id' => $holiday->calendar_id !== null ? (int) $holiday->calendar_id : null,
                    'company_id'  => $holiday->company_id !== null ? (int) $holiday->company_id : null,
                    'mandatory'   => false,
                ]));
            }
        }

        foreach ($mandatory as $day) {
            foreach ($this->datesBetween($day->start_date, $day->end_date, $from, $to) as $date) {
                $map->put($date, $map->get($date, collect())->push([
                    'name'        => $day->name,
                    'calendar_id' => $day->calendar_id !== null ? (int) $day->calendar_id : null,
                    'company_id'  => $day->company_id !== null ? (int) $day->company_id : null,
                    'mandatory'   => true,
                ]));
            }
        }

        return $map;
    }

    /**
     * First holiday on the date that applies to this employee's resolved
     * shift and company (null = shared/global).
     *
     * @param  Collection<string, Collection<int, array{name: string, calendar_id: ?int, company_id: ?int, mandatory?: bool}>>  $holidays
     * @return array{name: string, calendar_id: ?int, company_id: ?int, mandatory: bool}|null
     */
    protected function holidayEntryOnDay(Employee $employee, string $date, Collection $holidays): ?array
    {
        $entries = $holidays->get($date);

        if ($entries === null || $entries->isEmpty()) {
            return null;
        }

        $calendarId = $this->calendarFor($employee)?->id;
        $companyId = $employee->company_id !== null ? (int) $employee->company_id : null;

        foreach ($entries as $entry) {
            if ($entry['calendar_id'] !== null && (int) $entry['calendar_id'] !== (int) $calendarId) {
                continue;
            }

            if ($entry['company_id'] !== null && (int) $entry['company_id'] !== $companyId) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    /**
     * Mandatory days that demand attendance for this employee (policy
     * `treat_as_mandatory_workday`), even outside the normal roster.
     *
     * @param  Collection<string, Collection<int, array{name: string, calendar_id: ?int, company_id: ?int, mandatory?: bool}>>  $holidays
     * @return list<string>
     */
    protected function applicableMandatoryWorkdayDates(Employee $employee, Collection $holidays): array
    {
        $calendarId = $this->calendarFor($employee)?->id;
        $companyId = $employee->company_id !== null ? (int) $employee->company_id : null;

        return $holidays
            ->filter(function (Collection $entries, string $date) use ($calendarId, $companyId): bool {
                foreach ($entries as $entry) {
                    if (! ($entry['mandatory'] ?? false)) {
                        continue;
                    }

                    if ($entry['calendar_id'] !== null && (int) $entry['calendar_id'] !== (int) $calendarId) {
                        continue;
                    }

                    if ($entry['company_id'] !== null && (int) $entry['company_id'] !== $companyId) {
                        continue;
                    }

                    return true;
                }

                return false;
            })
            ->keys()
            ->values()
            ->all();
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
     * @param  Collection<string, Collection<int, array{name: string, calendar_id: ?int, company_id: ?int, mandatory?: bool}>>  $holidays
     * @return array<string, mixed>
     */
    protected function classify(Employee $employee, string $date, Collection $rows, Collection $leaves, Collection $holidays, bool $isWorkday = true): array
    {
        $dayRows = $rows->get($employee->getKey().'|'.$date, collect());
        $leave = $this->leaveOnDay($leaves->get($employee->getKey(), collect()), $date);
        $holiday = $this->holidayEntryOnDay($employee, $date, $holidays);
        $calendar = $this->calendarFor($employee);

        $leaveStatus = $leave ? $this->leaveStatus($leave) : null;
        $leaveApproved = $leaveStatus === self::STATUS_ON_LEAVE;
        $halfDay = (bool) $leave?->request_unit_half;

        $isHoliday = false;

        if ($holiday !== null) {
            if ($holiday['mandatory'] ?? false) {
                $isHoliday = AttendanceSettings::mandatoryDaysPolicy() === MandatoryDaysPolicy::TreatAsHoliday;
            } else {
                $holidayPolicy = ($calendar?->holiday_policy !== null && $calendar->holiday_policy !== HolidayPolicy::ExcuseAllApplicable)
                    ? $calendar->holiday_policy
                    : AttendanceSettings::holidayPolicy();

                $isHoliday = match ($holidayPolicy) {
                    HolidayPolicy::WorkRequired     => false,
                    HolidayPolicy::ExcuseShiftOnly  => $holiday['calendar_id'] !== null,
                    default                         => true,
                };
            }
        }

        $mandatoryWorkday = ($holiday['mandatory'] ?? false) && ! $isHoliday;

        $day = $this->resolver()->evaluateDay(
            $employee,
            $date,
            $dayRows,
            $isWorkday || $mandatoryWorkday,
            $halfDay,
            $leave,
            $isHoliday,
            $leaveApproved,
        );

        $leaveLabel = $leave?->holidayStatus?->name;

        if ($day['status'] === self::STATUS_HOLIDAY && $holiday !== null) {
            $leaveLabel = $holiday['name'];
        }

        $leaveMinutes = 0;

        if ($leave !== null && $day['leave_weight'] > 0) {
            if ((bool) $leave->request_unit_hours) {
                $leaveMinutes = (int) round(((float) $leave->number_of_hours) * 60);
            } else {
                $scheduled = $calendar
                    ? $this->resolver()->scheduledMinutesForDay($calendar, $date, $calendar->timezone)
                    : 0;

                $leaveMinutes = (int) round($scheduled * (float) $day['leave_weight']);
            }
        }

        return array_merge($day, [
            'name'          => $employee->name,
            'leave_label'   => $leaveLabel,
            'half_day'      => $halfDay,
            'leave_unpaid'  => (bool) $leave?->holidayStatus?->unpaid,
            'leave_minutes' => $leaveMinutes,
        ]);
    }

    /**
     * @param  Collection<int, Leave>  $leaves
     */
    protected function leaveOnDay(Collection $leaves, string $date): ?Leave
    {
        return $leaves->first(function (Leave $leave) use ($date): bool {
            $from = $leave->date_from ?? $leave->request_date_from;
            $to = $leave->date_to ?? $leave->date_from ?? $leave->request_date_to;

            if ($from === null || $to === null) {
                return false;
            }

            $start = $from instanceof Carbon ? $from->toDateString() : Carbon::parse($from)->toDateString();
            $stop = $to instanceof Carbon ? $to->toDateString() : Carbon::parse($to)->toDateString();

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
