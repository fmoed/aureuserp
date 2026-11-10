<?php

namespace Webkul\Support\Models;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Attendance\Enums\BreakPolicy;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Enums\HolidayPolicy;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Enums\PairingMode;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Employee\Models\Employee;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\CalendarFactory;
use Webkul\Support\Traits\BelongsToCompany;

class Calendar extends Model
{
    use BelongsToCompany;
    use HasCustomFields, HasFactory, SoftDeletes;

    protected $table = 'calendars';

    protected $fillable = [
        'name',
        'timezone',
        'hours_per_day',
        'is_active',
        'two_weeks_calendar',
        'flexible_hours',
        'full_time_required_hours',
        'shift_type',
        'pairing_mode',
        'check_in_window_before_minutes',
        'check_out_window_after_minutes',
        'late_grace_minutes',
        'early_leave_grace_minutes',
        'early_arrival_policy',
        'max_early_offset_minutes',
        'break_policy',
        'break_duration_minutes',
        'break_trigger_minutes',
        'missing_checkout_policy',
        'holiday_policy',
        'half_day_threshold_percent',
        'absent_threshold_percent',
        'overtime_enabled',
        'min_overtime_minutes',
        'resource_type',
        'resource_id',
        'creator_id',
        'company_id',
    ];

    protected $casts = [
        'shift_type'                     => ShiftType::class,
        'pairing_mode'                   => PairingMode::class,
        'early_arrival_policy'           => EarlyArrivalPolicy::class,
        'break_policy'                   => BreakPolicy::class,
        'missing_checkout_policy'        => MissingCheckoutPolicy::class,
        'holiday_policy'                 => HolidayPolicy::class,
        'check_in_window_before_minutes' => 'integer',
        'check_out_window_after_minutes' => 'integer',
        'late_grace_minutes'             => 'integer',
        'early_leave_grace_minutes'      => 'integer',
        'max_early_offset_minutes'       => 'integer',
        'break_duration_minutes'         => 'integer',
        'break_trigger_minutes'          => 'integer',
        'half_day_threshold_percent'     => 'integer',
        'absent_threshold_percent'       => 'integer',
        'overtime_enabled'               => 'boolean',
        'min_overtime_minutes'           => 'integer',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function attendance()
    {
        return $this->hasMany(CalendarAttendance::class);
    }

    public function publicHolidays(): HasMany
    {
        return $this->hasMany(CalendarLeave::class, 'calendar_id')
            ->where('time_type', 'leave')
            ->whereNull('resource_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'calendar_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($calendar) {
            $calendar->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory(): CalendarFactory
    {
        return CalendarFactory::new();
    }

    public function planHours(float $hours, Carbon $dayDt, bool $computeLeaves = false, ?Closure $filter = null, $resource = null): Carbon|false
    {
        [$dayDt, $revert] = make_aware($dayDt);

        if ($computeLeaves) {
            $getIntervals = fn ($start, $end) => $this->getWorkIntervalsBatch($start, $end, resources: $resource, filter: $filter);
            $resourceId = $resource?->id;
        } else {
            $getIntervals = fn ($start, $end) => $this->getAttendanceIntervalsBatch($start, $end);
            $resourceId = null;
        }

        $delta = 14;

        if ($hours >= 0) {
            for ($n = 0; $n < 100; $n++) {
                $dt = $dayDt->copy()->addDays($delta * $n);

                foreach ($getIntervals($dt, $dt->copy()->addDays($delta))[$resourceId] as [$start, $stop, $meta]) {
                    $intervalHours = $start->diffInSeconds($stop) / 3600;

                    if ($hours <= $intervalHours) {
                        return $revert($start->copy()->addSeconds($hours * 3600));
                    }

                    $hours -= $intervalHours;
                }
            }

            return false;
        } else {
            $hours = abs($hours);

            for ($n = 0; $n < 100; $n++) {
                $dt = $dayDt->copy()->subDays($delta * $n);

                $intervals = collect($getIntervals($dt->copy()->subDays($delta), $dt)[$resourceId])->reverse()->values();

                foreach ($intervals as [$start, $stop, $meta]) {
                    $intervalHours = $start->diffInSeconds($stop) / 3600;

                    if ($hours <= $intervalHours) {
                        return $revert($stop->copy()->subSeconds($hours * 3600));
                    }

                    $hours -= $intervalHours;
                }
            }

            return false;
        }
    }

    public function getAttendanceIntervalsDaysData(iterable $intervals): array
    {
        $hoursPerDay = [];

        foreach ($intervals as [$start, $stop]) {
            $day = $start->toDateString();

            $hoursPerDay[$day] = ($hoursPerDay[$day] ?? 0.0) + $start->diffInSeconds($stop) / 3600;
        }

        return [
            'days'  => count($hoursPerDay),
            'hours' => array_sum($hoursPerDay),
        ];
    }

    public function getWorkDurationData(
        Carbon $fromDatetime,
        Carbon $toDatetime,
        bool $computeLeaves = true,
        ?Closure $filter = null
    ): array {
        if ($computeLeaves) {
            $intervals = $this->getWorkIntervalsBatch($fromDatetime, $toDatetime, filter: $filter)[null];
        } else {
            $intervals = $this->getAttendanceIntervalsBatch($fromDatetime, $toDatetime, filter: $filter)[null];
        }

        return $this->getAttendanceIntervalsDaysData($intervals);
    }

    public function getWorkIntervalsBatch(
        Carbon $startDt,
        Carbon $endDt,
        mixed $resources = null,
        ?Closure $filter = null,
        ?string $timezone = null,
        bool $computeLeaves = true
    ): array {
        if (! $resources) {
            $resourcesList = [null];
        } else {
            $resourcesList = is_array($resources) ? array_merge($resources, [null]) : [$resources, null];
        }

        $attendanceIntervals = $this->getAttendanceIntervalsBatch($startDt, $endDt, $resources, timezone: $timezone);

        if ($computeLeaves) {
            $leaveIntervals = $this->getLeaveIntervalsBatch($startDt, $endDt, $resources, $filter, timezone: $timezone);

            $result = [];

            foreach ($resourcesList as $resource) {
                $resourceId = $resource?->id;

                $result[$resourceId] = $this->subtractIntervals($attendanceIntervals[$resourceId], $leaveIntervals[$resourceId]);
            }

            return $result;
        }

        $result = [];

        foreach ($resourcesList as $resource) {
            $resourceId = $resource?->id;

            $result[$resourceId] = $attendanceIntervals[$resourceId];
        }

        return $result;
    }

    public function getAttendanceIntervalsBatch(
        Carbon $startDt,
        Carbon $endDt,
        mixed $resources = null,
        ?Closure $filter = null,
        ?string $timezone = null,
        bool $lunch = false
    ): array {
        if (! $resources) {
            $resourcesList = [null];
        } else {
            $resourcesList = is_array($resources) ? array_merge($resources, [null]) : [$resources, null];
        }

        $resourcesByType = collect($resourcesList)
            ->filter()
            ->groupBy(fn ($resource) => $resource->getMorphClass())
            ->map(fn ($group) => $group->pluck('id')->all())
            ->all();

        $attendances = CalendarAttendance::query()
            ->when($filter, fn ($q) => $q->where($filter))
            ->where('calendar_id', $this->id)
            ->where(function ($q) use ($resourcesByType) {
                $q->whereNull('resource_id');
                foreach ($resourcesByType as $type => $ids) {
                    $q->orWhere(fn ($q) => $q->where('resource_type', $type)->whereIn('resource_id', $ids));
                }
            })
            ->whereNull('display_type')
            ->where('day_period', $lunch ? '=' : '!=', 'lunch')
            ->get();

        $resourcesPerTz = [];

        foreach ($resourcesList as $resource) {
            $resourceTz = $timezone
                ?? ($resource ? ($resource->timezone ?: null) : null)
                ?? $this->timezone;

            $resourcesPerTz[$resourceTz][] = $resource;
        }

        $attendancePerResource = [];

        $attendancesPerDay = array_map(fn () => [], range(0, 13));

        $weekdays = [];

        $dayOfWeekMap = [
            'monday'    => 0,
            'tuesday'   => 1,
            'wednesday' => 2,
            'thursday'  => 3,
            'friday'    => 4,
            'saturday'  => 5,
            'sunday'    => 6,
        ];

        foreach ($attendances as $attendance) {
            if ($attendance->resource_id) {
                $attendancePerResource[$attendance->resource_id][] = $attendance;
            }

            $weekday = $dayOfWeekMap[$attendance->day_of_week] ?? 0;

            $weekdays[] = $weekday;

            if ($this->two_weeks_calendar) {
                $weekType = (int) $attendance->week_type;

                $attendancesPerDay[$weekday + 7 * $weekType][] = $attendance;
            } else {
                $attendancesPerDay[$weekday][] = $attendance;
                $attendancesPerDay[$weekday + 7][] = $attendance;
            }
        }

        $weekdays = array_unique($weekdays);

        $start = $startDt->clone()->utc();

        $end = $endDt->clone()->utc();

        $boundsPerTz = [];

        foreach ($resourcesPerTz as $resourceTz => $tzResources) {
            $boundsPerTz[$resourceTz] = [
                $startDt->clone()->setTimezone($resourceTz),
                $endDt->clone()->setTimezone($resourceTz),
            ];

            $start = min($start, $boundsPerTz[$resourceTz][0]->clone()->utc());

            $end = max($end, $boundsPerTz[$resourceTz][1]->clone()->utc());
        }

        $baseResult = [];

        $perResourceResult = [];

        $current = $start->clone()->startOfDay();

        while ($current->lte($end)) {
            $systemDay = $current->dayOfWeek === 0 ? 6 : $current->dayOfWeek - 1;

            if (in_array($systemDay, $weekdays)) {
                $weekType = CalendarAttendance::getWeekType($current);

                $dayAttends = $attendancesPerDay[$systemDay + 7 * $weekType];

                foreach ($dayAttends as $attendance) {
                    if (
                        ($attendance->date_from && $current->lt(Carbon::parse($attendance->date_from))) ||
                        ($attendance->date_to && Carbon::parse($attendance->date_to)->lt($current))
                    ) {
                        continue;
                    }

                    $fromMinutes = CalendarAttendance::minutesOfDay($attendance->getRawOriginal('hour_from') ?? $attendance->hour_from);
                    $toMinutes = CalendarAttendance::minutesOfDay($attendance->getRawOriginal('hour_to') ?? $attendance->hour_to);

                    $dayFrom = $current->clone()->setTime(0, 0)->addMinutes($fromMinutes);

                    $dayTo = $current->clone()->setTime(0, 0)->addMinutes($toMinutes);

                    if ($toMinutes <= $fromMinutes && $fromMinutes > 0) {
                        $dayTo->addDay();
                    }

                    if ($attendance->resource_id) {
                        $perResourceResult[$attendance->resource_id][] = [$dayFrom, $dayTo, $attendance];
                    } else {
                        $baseResult[] = [$dayFrom, $dayTo, $attendance];
                    }
                }
            }

            $current->addDay();
        }

        $resultPerTz = [];

        // A night interval belongs to the day it starts on. When that day is
        // inside the requested range, keep its full natural end instead of
        // clipping it at the last requested midnight (a 22:00-06:00 shift
        // queried for one day must stay eight hours, not two).
        $clip = function (array $val, array $bounds, string $resourceTz): array {
            $start = Carbon::parse($val[0])->setTimezone($resourceTz);
            $end = Carbon::parse($val[1])->setTimezone($resourceTz);

            $clippedStart = $start->lt($bounds[0]) ? $bounds[0]->copy() : $start;

            $startsInsideRange = $start->gte($bounds[0]) && $start->lte($bounds[1]);
            $crossesMidnight = $end->toDateString() !== $start->toDateString();

            $clippedEnd = ($startsInsideRange && $crossesMidnight) || $end->lte($bounds[1])
                ? $end
                : $bounds[1]->copy();

            return [$clippedStart, $clippedEnd, $val[2]];
        };

        foreach ($resourcesPerTz as $resourceTz => $tzResources) {
            $bounds = $boundsPerTz[$resourceTz];

            $resultPerTz[$resourceTz] = collect($baseResult)
                ->map(fn ($val) => $clip($val, $bounds, $resourceTz))
                ->filter(fn ($interval) => $interval[0]->lt($interval[1]))
                ->values()
                ->all();
        }

        $resultPerResourceId = [];

        foreach ($resourcesPerTz as $resourceTz => $tzResources) {
            $res = $resultPerTz[$resourceTz];

            foreach ($tzResources as $resource) {
                $resourceId = $resource?->id;

                if ($resource && isset($perResourceResult[$resourceId])) {
                    $bounds = $boundsPerTz[$resourceTz];

                    $resourceSpecificResult = collect($perResourceResult[$resourceId])
                        ->map(fn ($val) => $clip($val, $bounds, $resourceTz))
                        ->filter(fn ($interval) => $interval[0]->lt($interval[1]))
                        ->values()
                        ->all();

                    $resultPerResourceId[$resourceId] = collect(array_merge($res, $resourceSpecificResult));
                } else {
                    $resultPerResourceId[$resourceId] = collect($res);
                }
            }
        }

        return $resultPerResourceId;
    }

    public function getLeaveIntervalsBatch(
        Carbon $startDt,
        Carbon $endDt,
        mixed $resources = null,
        ?Closure $filter = null,
        ?string $timezone = null,
        bool $anyCalendar = false
    ): array {
        if (! $resources) {
            $resourcesList = [null];
        } else {
            $resourcesList = is_array($resources) ? array_merge($resources, [null]) : [$resources, null];
        }

        $filter ??= fn ($query) => $query->where('time_type', 'leave');

        $resourcesByType = collect($resourcesList)
            ->filter()
            ->groupBy(fn ($resource) => $resource->getMorphClass())
            ->map(fn ($group) => $group->pluck('id')->all())
            ->all();

        $allLeaves = CalendarLeave::query()
            ->where(fn ($q) => $filter($q))
            ->when(! $anyCalendar, fn ($q) => $q->where(fn ($q) => $q->whereNull('calendar_id')->orWhere('calendar_id', $this->id)))
            ->where(function ($q) use ($resourcesByType) {
                $q->whereNull('resource_id');
                foreach ($resourcesByType as $type => $ids) {
                    $q->orWhere(fn ($q) => $q->where('resource_type', $type)->whereIn('resource_id', $ids));
                }
            })
            ->where('date_from', '<=', $endDt->toDateTimeString())
            ->where('date_to', '>=', $startDt->toDateTimeString())
            ->get();

        $result = [];

        $tzDates = [];

        foreach ($allLeaves as $leave) {
            $leaveResource = $leave->resource;

            $leaveCompany = $leave->company;

            $leaveDateFrom = $leave->date_from;

            $leaveDateTo = $leave->date_to;

            foreach ($resourcesList as $resource) {
                if (
                    $leaveResource
                    && $leaveResource->id !== ($resource?->id)
                    || (
                        ! $leaveResource
                        && $resource
                        && $resource->company_id !== $leaveCompany?->id
                    )
                ) {
                    continue;
                }

                $resourceTz = $timezone
                    ?? ($resource ? ($resource->timezone ?: null) : null)
                    ?? $this->timezone;
                $resourceId = $resource?->id;

                $tzKey = $resourceTz.'_start';

                if (! isset($tzDates[$tzKey])) {
                    $tzDates[$tzKey] = $startDt->clone()->setTimezone($resourceTz);
                }

                $start = $tzDates[$tzKey];

                $tzKey = $resourceTz.'_end';

                if (! isset($tzDates[$tzKey])) {
                    $tzDates[$tzKey] = $endDt->clone()->setTimezone($resourceTz);
                }

                $end = $tzDates[$tzKey];

                $dt0 = Carbon::parse($leaveDateFrom)->setTimezone($resourceTz);

                $dt1 = Carbon::parse($leaveDateTo)->setTimezone($resourceTz);

                $result[$resourceId][] = [
                    Carbon::parse(max($start, $dt0)),
                    Carbon::parse(min($end, $dt1)),
                    $leave,
                ];
            }
        }

        $finalResult = [];

        foreach ($resourcesList as $resource) {
            $resourceId = $resource?->id;

            $finalResult[$resourceId] = collect($result[$resourceId] ?? []);
        }

        return $finalResult;
    }

    protected function subtractIntervals($attendance, $leaves): array
    {
        $result = [];

        foreach ($attendance as [$start, $stop, $rec]) {
            $remaining = [[$start, $stop, $rec]];

            foreach ($leaves as [$leaveStart, $leaveStop]) {
                $split = [];

                foreach ($remaining as [$s, $e, $r]) {
                    if ($s->lt($leaveStart)) {
                        $split[] = [$s->copy(), ($e->lt($leaveStart) ? $e : $leaveStart)->copy(), $r];
                    }

                    if ($e->gt($leaveStop)) {
                        $split[] = [($s->gt($leaveStop) ? $s : $leaveStop)->copy(), $e->copy(), $r];
                    }
                }

                $remaining = $split;
            }

            array_push($result, ...$remaining);
        }

        return $result;
    }

    public function recalculateSummaryHours(): void
    {
        if ($this->shift_type === ShiftType::Open) {
            return;
        }

        $rows = $this->attendance()
            ->whereNull('display_type')
            ->whereNull('resource_id')
            ->whereNull('date_from')
            ->whereNull('date_to')
            ->where('day_period', '!=', 'lunch')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $isTwoWeeks = (bool) $this->two_weeks_calendar;
        $grouped = $rows->groupBy(function (CalendarAttendance $row) use ($isTwoWeeks): string {
            $key = strtolower((string) $row->day_of_week);
            if ($isTwoWeeks && $row->week_type !== null) {
                $key .= ':'.$row->week_type;
            }

            return $key;
        });

        $totalMinutes = 0;
        $workdayCount = 0;

        foreach ($grouped as $dayRows) {
            $gross = 0;
            foreach ($dayRows as $row) {
                $from = (int) round(CalendarAttendance::minutesOfDay($row->getRawOriginal('hour_from') ?? $row->hour_from));
                $to = (int) round(CalendarAttendance::minutesOfDay($row->getRawOriginal('hour_to') ?? $row->hour_to));
                if ($to <= $from && $from > 0) {
                    $to += 1440;
                }
                $gross += max(0, $to - $from);
            }

            if ($gross > 0) {
                $totalMinutes += $gross;
                $workdayCount++;
            }
        }

        if ($workdayCount === 0) {
            return;
        }

        if ($isTwoWeeks) {
            $weeklyHours = round(($totalMinutes / 2) / 60, 2);
            $dailyHours = round(($totalMinutes / $workdayCount) / 60, 2);
        } else {
            $weeklyHours = round($totalMinutes / 60, 2);
            $dailyHours = round(($totalMinutes / $workdayCount) / 60, 2);
        }

        $this->forceFill([
            'hours_per_day'            => $dailyHours,
            'full_time_required_hours' => $weeklyHours,
        ])->saveQuietly();
    }
}
