<?php

namespace Webkul\Attendance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Attendance\Models\Attendance;
use Webkul\Employee\Models\Employee;
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
 * confirm are shown as pending (and count as absent); leave types with
 * no_validation count from confirm; holidays come only from manually
 * entered calendar leaves and mandatory days.
 */
class AttendanceOverviewService
{
    public const MAX_RANGE_DAYS = 93;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_ON_LEAVE_PENDING = 'on_leave_pending';

    public const STATUS_HOLIDAY = 'holiday';

    public static function rangeDays(string|Carbon $from, string|Carbon $to): int
    {
        return Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay());
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
     * @param  Collection<int, array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}>  $entries
     * @return array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}
     */
    protected function summarize(Employee $employee, Collection $entries): array
    {
        $workdays = $entries->count();
        $absent = $entries->whereIn('status', [self::STATUS_ABSENT, self::STATUS_ON_LEAVE_PENDING])->count();

        return [
            'employee_id'  => $employee->getKey(),
            'name'         => $employee->name,
            'workdays'     => $workdays,
            'present'      => $entries->where('status', self::STATUS_PRESENT)->count(),
            'absent'       => $absent,
            'on_leave'     => $entries->where('status', self::STATUS_ON_LEAVE)->count(),
            'holidays'     => $entries->where('status', self::STATUS_HOLIDAY)->count(),
            'pending'      => $entries->where('status', self::STATUS_ON_LEAVE_PENDING)->count(),
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

        $intervals = collect($calendar->getAttendanceIntervalsBatch(
            Carbon::parse($from.' 00:00:00', 'UTC'),
            Carbon::parse($to.' 23:59:59', 'UTC'),
            timezone: $timezone,
        ))->first() ?? [];

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
     * A null-company calendar is the normal single-roster setup, so it
     * must apply instead of yielding zero workdays.
     */
    protected function calendarFor(Employee $employee): ?Calendar
    {
        if ($employee->calendar) {
            return $employee->calendar;
        }

        if ($employee->company_id !== null) {
            $companyCalendar = Calendar::query()
                ->where('company_id', $employee->company_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if ($companyCalendar) {
                return $companyCalendar;
            }
        }

        return Calendar::query()
            ->whereNull('company_id')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
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
     * @return Collection<string, string> date (Y-m-d) => holiday label.
     */
    protected function holidaysByDate(Collection $employees, string $from, string $to): Collection
    {
        $calendarIds = $employees->pluck('calendar_id')->filter()->unique()->values()->all();
        $companyIds = $employees->pluck('company_id')->filter()->unique()->values()->all();

        $public = CalendarLeave::query()
            ->whereDate('date_to', '>=', $from)
            ->whereDate('date_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('calendar_id')->orWhereIn('calendar_id', $calendarIds))
            ->where(fn ($query) => $query->whereNull('company_id')->orWhereIn('company_id', $companyIds))
            ->get();

        $mandatory = LeaveMandatoryDay::query()
            ->where('end_date', '>=', $from)
            ->where('start_date', '<=', $to)
            ->where(fn ($query) => $query->whereNull('company_id')->orWhereIn('company_id', $companyIds))
            ->get();

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
