<?php

namespace Webkul\Attendance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Attendance\Enums\BreakPolicy;
use Webkul\Attendance\Enums\EarlyArrivalPolicy;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Enums\RoundingMode;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\TimeOff\Models\Leave;

/**
 * Resolves the logical shift workday of a punch and evaluates a day against
 * the shift's policy engine. Pure read model: the pairing service and the
 * reporting service share it so a night shift that crosses midnight counts
 * once, on the day its window opened.
 */
class ShiftWindowResolver
{
    public function __construct(protected AttendanceOverviewService $overview) {}

    /**
     * @var array<string, list<array>>
     */
    private array $windowCache = [];

    public function resolveCalendarFor(Employee $employee): ?Calendar
    {
        return $this->overview->calendarFor($employee);
    }

    /**
     * Every working period of the calendar on the given logical day, with
     * its acceptance window. Lunch periods and display-only rows are not
     * working windows.
     *
     * @return list<array{work_date: string, period_id: ?int, day_period: string, shift_start: Carbon, shift_end: Carbon, window_open: Carbon, window_close: Carbon, scheduled_minutes: int}>
     */
    public function windowsForDate(Calendar $calendar, string $workDate, string $timezone): array
    {
        $cacheKey = $calendar->getKey().'|'.$workDate.'|'.$timezone;

        if (isset($this->windowCache[$cacheKey])) {
            return $this->windowCache[$cacheKey];
        }

        $date = Carbon::parse($workDate, $timezone)->startOfDay();
        $weekday = strtolower($date->englishDayOfWeek);
        $weekType = CalendarAttendance::getWeekType($date);

        $openBefore = max(0, (int) ($calendar->check_in_window_before_minutes ?? 120));
        $closeAfter = max(0, (int) ($calendar->check_out_window_after_minutes ?? 240));

        $periods = $calendar->relationLoaded('attendance')
            ? $calendar->attendance
            : $calendar->attendance()->get();

        $windows = [];
        $hasDatedPeriod = false;

        foreach ($periods as $period) {
            if ($period->display_type !== null || $period->day_period === 'lunch') {
                continue;
            }

            if ($period->resource_id !== null) {
                continue;
            }

            if (strtolower((string) $period->day_of_week) !== $weekday) {
                continue;
            }

            if ($calendar->two_weeks_calendar && (int) ($period->week_type ?? 0) !== $weekType) {
                continue;
            }

            if ($period->date_from && $date->lt(Carbon::parse($period->date_from, $timezone)->startOfDay())) {
                continue;
            }

            if ($period->date_to && $date->gt(Carbon::parse($period->date_to, $timezone)->endOfDay())) {
                continue;
            }

            $isDated = $period->date_from !== null || $period->date_to !== null;

            $hasDatedPeriod = $hasDatedPeriod || $isDated;

            $fromMinutes = (int) round(CalendarAttendance::minutesOfDay($period->getRawOriginal('hour_from') ?? $period->hour_from));
            $toMinutes = (int) round(CalendarAttendance::minutesOfDay($period->getRawOriginal('hour_to') ?? $period->hour_to));

            $start = $date->copy()->addMinutes($fromMinutes);
            $end = $date->copy()->addMinutes($toMinutes);

            if ($toMinutes <= $fromMinutes && $fromMinutes > 0) {
                $end->addDay();
            }

            $windows[] = [
                'work_date'         => $workDate,
                'period_id'         => $period->getKey() !== null ? (int) $period->getKey() : null,
                'day_period'        => (string) $period->day_period,
                'shift_start'       => $start,
                'shift_end'         => $end,
                'window_open'       => $start->copy()->subMinutes($openBefore),
                'window_close'      => $end->copy()->addMinutes($closeAfter),
                'scheduled_minutes' => max(0, (int) round($start->diffInMinutes($end))),
                'dated'             => $isDated,
            ];
        }

        // A season-specific period (Ramadan hours) replaces the undated
        // default for the days it covers instead of adding to it.
        if ($hasDatedPeriod) {
            $dated = array_values(array_filter($windows, fn (array $window): bool => $window['dated']));

            if ($dated !== []) {
                $windows = $dated;
            }
        }

        usort($windows, fn (array $a, array $b): int => $a['shift_start']->getTimestamp() <=> $b['shift_start']->getTimestamp());

        for ($i = 0; $i + 1 < count($windows); $i++) {
            $currentEnd = $windows[$i]['shift_end'];
            $nextStart = $windows[$i + 1]['shift_start'];

            if ($nextStart->gt($currentEnd)) {
                $midpoint = $currentEnd->copy()->addSeconds((int) floor($currentEnd->diffInSeconds($nextStart) / 2));

                if ($windows[$i]['window_close']->gt($midpoint)) {
                    $windows[$i]['window_close'] = $midpoint->copy();
                }

                if ($windows[$i + 1]['window_open']->lt($midpoint)) {
                    $windows[$i + 1]['window_open'] = $midpoint->copy();
                }
            }
        }

        $windows = array_map(function (array $window): array {
            unset($window['dated']);

            return $window;
        }, $windows);

        return $this->windowCache[$cacheKey] = $windows;
    }

    /**
     * Break minutes implied by the roster itself: explicit lunch periods,
     * or the gaps between split work periods. A single continuous period
     * carries no scheduled break.
     *
     * @param  list<array{shift_start: Carbon, shift_end: Carbon}>  $windows
     */
    public function scheduledBreakMinutes(Calendar $calendar, string $workDate, string $timezone, array $windows = []): int
    {
        $lunch = $this->lunchMinutesForDay($calendar, $workDate, $timezone);

        if ($lunch > 0) {
            return $lunch;
        }

        if (count($windows) < 2) {
            return 0;
        }

        $windows = array_values($windows);
        $gap = 0;

        for ($i = 1; $i < count($windows); $i++) {
            $gap += max(0, (int) round($windows[$i - 1]['shift_end']->diffInMinutes($windows[$i]['shift_start'])));
        }

        return $gap;
    }

    public function lunchMinutesForDay(Calendar $calendar, string $workDate, string $timezone): int
    {
        $date = Carbon::parse($workDate, $timezone)->startOfDay();
        $weekday = strtolower($date->englishDayOfWeek);

        $periods = $calendar->relationLoaded('attendance')
            ? $calendar->attendance
            : $calendar->attendance()->get();

        $lunch = 0;

        foreach ($periods as $period) {
            if ($period->display_type !== null || $period->day_period !== 'lunch' || $period->resource_id !== null) {
                continue;
            }

            if (strtolower((string) $period->day_of_week) !== $weekday) {
                continue;
            }

            if ($period->date_from && $date->lt(Carbon::parse($period->date_from, $timezone)->startOfDay())) {
                continue;
            }

            if ($period->date_to && $date->gt(Carbon::parse($period->date_to, $timezone)->endOfDay())) {
                continue;
            }

            $fromMinutes = (int) round(CalendarAttendance::minutesOfDay($period->getRawOriginal('hour_from') ?? $period->hour_from));
            $toMinutes = (int) round(CalendarAttendance::minutesOfDay($period->getRawOriginal('hour_to') ?? $period->hour_to));

            $lunch += max(0, $toMinutes - $fromMinutes);
        }

        return $lunch;
    }

    public function scheduledMinutesForDay(Calendar $calendar, string $workDate, string $timezone): int
    {
        $windows = $this->windowsForDate($calendar, $workDate, $timezone);

        if ($windows !== []) {
            $scheduled = array_sum(array_column($windows, 'scheduled_minutes')) - $this->lunchMinutesForDay($calendar, $workDate, $timezone);

            return max(0, $scheduled);
        }

        if ($calendar->shift_type === ShiftType::Open) {
            return max(0, (int) round(((float) $calendar->hours_per_day) * 60));
        }

        return 0;
    }

    /**
     * Logical workday that owns the punch: the shift window (yesterday,
     * today or tomorrow) whose acceptance range contains it. When no
     * configured window matches (weekend work, open shift) the punch keeps
     * its own local date.
     *
     * @return array{work_date: string, window: ?array}
     */
    public function matchPunchToWindow(Employee $employee, Carbon $punchUtc): array
    {
        $calendar = $this->resolveCalendarFor($employee);
        $timezone = $this->timezoneFor($employee, $calendar);

        $localDate = $punchUtc->copy()->setTimezone($timezone);

        if (! $calendar) {
            return ['work_date' => $localDate->toDateString(), 'window' => null];
        }

        $candidates = [];

        for ($offset = -1; $offset <= 1; $offset++) {
            $workDate = $localDate->copy()->addDays($offset)->toDateString();

            foreach ($this->windowsForDate($calendar, $workDate, $timezone) as $window) {
                if ($punchUtc->gte($window['window_open']) && $punchUtc->lte($window['window_close'])) {
                    $candidates[] = $window;
                }
            }
        }

        if ($candidates === []) {
            return ['work_date' => $localDate->toDateString(), 'window' => null];
        }

        usort($candidates, fn (array $a, array $b): int => $a['window_close']->getTimestamp() <=> $b['window_close']->getTimestamp()
            ?: $b['window_open']->getTimestamp() <=> $a['window_open']->getTimestamp());

        return ['work_date' => $candidates[0]['work_date'], 'window' => $candidates[0]];
    }

    /**
     * Full policy evaluation of one logical day.
     *
     * @param  Collection<int, Attendance>  $dayRows
     * @return array<string, mixed>
     */
    public function evaluateDay(
        Employee $employee,
        string $workDate,
        Collection $dayRows,
        bool $isWorkday,
        bool $isHalfDayLeave = false,
        ?Leave $leave = null,
        bool $isHoliday = false,
        bool $leaveApproved = true,
    ): array {
        $calendar = $this->resolveCalendarFor($employee);
        $timezone = $this->timezoneFor($employee, $calendar);
        $windows = $calendar ? $this->windowsForDate($calendar, $workDate, $timezone) : [];

        $scheduled = $calendar ? $this->scheduledMinutesForDay($calendar, $workDate, $timezone) : 0;

        if ($isHoliday) {
            $scheduled = 0;
        }

        $leaveMinutes = 0;

        if ($leaveApproved && $leave && ! $isHalfDayLeave) {
            $leaveMinutes = $this->leaveMinutes($leave);
            $scheduled = max(0, $scheduled - $leaveMinutes);
        }

        if ($isHalfDayLeave && $leaveApproved) {
            $scheduled = (int) round($scheduled / 2);
        }

        $scheduled = $this->round($scheduled, $calendar);

        $shiftStart = $windows[0]['shift_start'] ?? null;
        $shiftEnd = $windows[count($windows) - 1]['shift_end'] ?? null;

        $checkIn = $dayRows->map(fn (Attendance $row): ?string => $row->getRawOriginal('check_in'))->filter()->min();
        $checkOut = $dayRows->map(fn (Attendance $row): ?string => $row->getRawOriginal('check_out'))->filter()->max();

        $firstIn = $checkIn ? Carbon::parse($checkIn, 'UTC') : null;
        $lastOut = $checkOut ? Carbon::parse($checkOut, 'UTC') : null;

        $gross = 0;

        foreach ($dayRows as $row) {
            if ($row->check_in && $row->check_out) {
                $gross += max(0, (int) round($row->check_in->diffInMinutes($row->check_out)));
            }
        }

        $explicitBreak = (int) $dayRows->sum(fn (Attendance $row): int => (int) ($row->break_minutes ?? 0));
        $closedRows = $dayRows
            ->filter(fn (Attendance $row): bool => $row->check_in !== null && $row->check_out !== null)
            ->sortBy(fn (Attendance $row): int => $row->check_in->getTimestamp())
            ->values();

        for ($index = 1; $index < $closedRows->count(); $index++) {
            $previousOut = $closedRows[$index - 1]->check_out;
            $nextIn = $closedRows[$index]->check_in;

            if ($previousOut && $nextIn->gt($previousOut)) {
                $explicitBreak += (int) round($previousOut->diffInMinutes($nextIn));
            }
        }

        $missingCheckout = $dayRows->isNotEmpty()
            && $dayRows->every(fn (Attendance $row): bool => $row->getRawOriginal('check_out') === null);

        $missingPolicy = $calendar?->missing_checkout_policy ?? AttendanceSettings::defaultMissingCheckoutPolicy();

        $policy = ($calendar?->early_arrival_policy !== null && $calendar->early_arrival_policy !== EarlyArrivalPolicy::ClampToSchedule)
            ? $calendar->early_arrival_policy
            : AttendanceSettings::earlyArrivalPolicy();
        $earlyOffset = ($calendar?->max_early_offset_minutes !== null && (int) $calendar->max_early_offset_minutes !== 60)
            ? max(0, (int) $calendar->max_early_offset_minutes)
            : AttendanceSettings::maxEarlyOffsetMinutes();
        $minOvertime = max(0, (int) ($calendar?->min_overtime_minutes ?? 30));
        $overtimeEnabled = $calendar?->overtime_enabled ?? true;

        $span = 0;
        $earlyOvertime = 0;

        if ($firstIn && $lastOut && $lastOut->gte($firstIn)) {
            $countStart = $firstIn;

            if ($shiftStart) {
                $countStart = match ($policy) {
                    EarlyArrivalPolicy::ClampToSchedule      => $firstIn->lt($shiftStart) ? $shiftStart : $firstIn,
                    EarlyArrivalPolicy::OffsetEarlyDeparture => $firstIn->lt($shiftStart->copy()->subMinutes($earlyOffset))
                        ? $shiftStart->copy()->subMinutes($earlyOffset)
                        : $firstIn,
                    EarlyArrivalPolicy::CountAsOvertime       => $firstIn->lt($shiftStart) ? $shiftStart : $firstIn,
                };

                if ($policy === EarlyArrivalPolicy::CountAsOvertime && $firstIn->lt($shiftStart)) {
                    $early = min($earlyOffset, max(0, (int) round($firstIn->diffInMinutes($shiftStart))));

                    if ($early >= $minOvertime) {
                        $earlyOvertime = $early;
                    }
                }
            }

            $span = max(0, (int) round($countStart->diffInMinutes($lastOut)));
        }

        $break = $this->breakMinutes(
            $calendar,
            $gross,
            $span,
            $explicitBreak,
            $scheduled,
            $calendar ? $this->scheduledBreakMinutes($calendar, $workDate, $timezone, $windows) : 0,
        );
        $netSpan = max(0, $span - $break);

        $regular = $scheduled > 0 ? min($netSpan, $scheduled) : 0;

        $rawOvertime = $scheduled > 0 ? max(0, $netSpan - $scheduled) : $netSpan;
        $rawOvertime += $earlyOvertime;

        $overtime = $overtimeEnabled && $rawOvertime >= $minOvertime ? $rawOvertime : 0;

        $worked = $overtimeEnabled
            ? ($scheduled > 0 ? $regular + $overtime : $overtime)
            : $regular;

        $expectedStart = $shiftStart?->copy();
        $expectedEnd = $shiftEnd?->copy();

        if ($expectedStart && $leaveMinutes > 0) {
            $leaveStart = $this->clockMinutes($leave?->request_hour_from);
            $leaveEnd = $this->clockMinutes($leave?->request_hour_to);
            $shiftStartMinutes = (int) ($shiftStart->copy()->setTimezone($timezone)->hour * 60
                + $shiftStart->copy()->setTimezone($timezone)->minute);

            if ($leaveStart !== null && $leaveStart <= $shiftStartMinutes) {
                $expectedStart->addMinutes($leaveMinutes);
            }

            if ($leaveEnd !== null && $shiftEnd && $leaveEnd >= $shiftStartMinutes + max(0, (int) round($shiftStart->diffInMinutes($shiftEnd)))) {
                $expectedEnd->subMinutes($leaveMinutes);
            }
        }

        if ($isHalfDayLeave && $leaveApproved && $shiftStart && $shiftEnd) {
            $period = $leave?->request_date_from_period;
            $period = $period instanceof \BackedEnum ? $period->value : $period;

            $midpoint = $shiftStart->copy()->addMinutes((int) round($shiftStart->diffInMinutes($shiftEnd) / 2));

            if ($period === 'morning') {
                $expectedStart = count($windows) > 1 ? $windows[1]['shift_start']->copy() : $midpoint;
            } elseif ($period === 'afternoon') {
                $expectedEnd = count($windows) > 1 ? $windows[count($windows) - 2]['shift_end']->copy() : $midpoint;
            }
        }

        $late = 0;
        $earlyLeave = 0;

        if ($firstIn && $expectedStart) {
            $lateGrace = max(0, (int) ($calendar?->late_grace_minutes ?? 15));
            $delta = max(0, (int) round($expectedStart->diffInMinutes($firstIn, false)));

            if ($delta > $lateGrace) {
                $late = $delta;
            }
        }

        if ($lastOut && $expectedEnd) {
            $earlyGrace = max(0, (int) ($calendar?->early_leave_grace_minutes ?? 10));
            $earlyArrivalCredit = $policy === EarlyArrivalPolicy::OffsetEarlyDeparture && $shiftStart && $firstIn && $firstIn->lt($shiftStart)
                ? min($earlyOffset, max(0, (int) round($firstIn->diffInMinutes($shiftStart, false))))
                : 0;

            $early = max(0, (int) round($lastOut->diffInMinutes($expectedEnd, false)));

            if ($early > $earlyGrace) {
                $earlyLeave = max(0, $early - $earlyArrivalCredit);
            }

            $skippedBreakCoversSchedule = $calendar?->break_policy === BreakPolicy::AllowSkipForEarlyExit
                && $break === 0
                && $scheduled > 0
                && $netSpan >= $scheduled;

            if ($skippedBreakCoversSchedule) {
                $earlyLeave = 0;
            }
        }

        $presentWeight = 0.0;
        $absentWeight = 0.0;
        $leaveWeight = 0.0;
        $status = AttendanceOverviewService::STATUS_ABSENT;
        $isEstimated = false;
        $weekendPresent = false;
        $holidayPresent = false;

        $isOffOrHoliday = $isHoliday || ! $isWorkday;

        if ($dayRows->isNotEmpty() && (! $isOffOrHoliday || ($overtimeEnabled && ! $missingCheckout && $worked > 0))) {
            $status = AttendanceOverviewService::STATUS_PRESENT;
            $presentWeight = 1.0;

            if ($missingCheckout) {
                $status = AttendanceOverviewService::STATUS_MISSING_CHECKOUT;

                match ($missingPolicy) {
                    MissingCheckoutPolicy::IncompleteAbsent => (function () use (&$worked, &$regular, &$overtime, &$late, &$earlyLeave, &$absentWeight): void {
                        $worked = 0;
                        $regular = 0;
                        $overtime = 0;
                        $late = 0;
                        $earlyLeave = 0;
                        $absentWeight = 1.0;
                    })(),
                    MissingCheckoutPolicy::IncompleteHalfDay => (function () use (&$worked, &$regular, &$overtime, &$presentWeight, &$absentWeight, $scheduled): void {
                        $worked = (int) round($scheduled / 2);
                        $regular = $worked;
                        $overtime = 0;
                        $presentWeight = 0.5;
                        $absentWeight = 0.5;
                    })(),
                    MissingCheckoutPolicy::AutoCreditScheduled => (function () use (&$worked, &$regular, &$overtime, &$status, &$isEstimated, $scheduled): void {
                        $worked = $scheduled;
                        $regular = $scheduled;
                        $overtime = 0;
                        $status = AttendanceOverviewService::STATUS_PRESENT;
                        $isEstimated = true;
                    })(),
                };
            } else {
                if ($isHoliday) {
                    $status = AttendanceOverviewService::STATUS_HOLIDAY_PRESENT;
                    $holidayPresent = true;
                } elseif (! $isWorkday) {
                    $status = AttendanceOverviewService::STATUS_WEEKEND_PRESENT;
                    $weekendPresent = true;
                }

                $halfThreshold = ($calendar?->half_day_threshold_percent !== null && (int) $calendar->half_day_threshold_percent !== 50)
                    ? max(0, (int) $calendar->half_day_threshold_percent)
                    : AttendanceSettings::halfDayThresholdPercent();

                if (
                    ! $weekendPresent
                    && ! $holidayPresent
                    && $isHalfDayLeave
                    && $leaveApproved
                ) {
                    // Half-day leave plus the other half worked: half a
                    // present day and half a leave day, no absence.
                    $status = AttendanceOverviewService::STATUS_HALF_DAY;
                    $presentWeight = 0.5;
                    $leaveWeight = 0.5;
                    $absentWeight = 0.0;
                } elseif (
                    ! $weekendPresent
                    && ! $holidayPresent
                    && $scheduled > 0
                    && $worked < (int) round($scheduled * $halfThreshold / 100)
                ) {
                    $status = AttendanceOverviewService::STATUS_HALF_DAY;
                    $presentWeight = 0.5;
                    $absentWeight = 0.5;
                }
            }
        } elseif ($isHoliday) {
            $status = AttendanceOverviewService::STATUS_HOLIDAY;
            $worked = 0;
            $regular = 0;
            $overtime = 0;
            $late = 0;
            $earlyLeave = 0;
            $missingCheckout = false;
        } elseif (! $isWorkday) {
            $status = AttendanceOverviewService::STATUS_WEEKLY_OFF;
            $worked = 0;
            $regular = 0;
            $overtime = 0;
            $late = 0;
            $earlyLeave = 0;
            $missingCheckout = false;
        } elseif ($leave && $leaveApproved) {
            $status = AttendanceOverviewService::STATUS_ON_LEAVE;

            if ($isHalfDayLeave) {
                $leaveWeight = 0.5;
                $absentWeight = 0.5;
            } else {
                $leaveWeight = 1.0;
            }
        } elseif ($leave) {
            $status = AttendanceOverviewService::STATUS_ON_LEAVE_PENDING;

            if (AttendanceSettings::countPendingLeaveAsAbsent()) {
                $absentWeight = $isHalfDayLeave ? 0.5 : 1.0;
            }
        } else {
            $status = AttendanceOverviewService::STATUS_ABSENT;
            $absentWeight = 1.0;
        }

        $breakForReport = $missingCheckout && $missingPolicy !== MissingCheckoutPolicy::AutoCreditScheduled
            ? ($missingPolicy === MissingCheckoutPolicy::IncompleteHalfDay ? $break : 0)
            : $break;

        return [
            'employee_id'         => $employee->getKey(),
            'date'                => $workDate,
            'status'              => $status,
            'half_day'            => $isHalfDayLeave,
            'check_in'            => $firstIn?->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'check_out'           => $lastOut?->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'minutes'             => $worked,
            'scheduled_minutes'   => $scheduled,
            'gross_minutes'       => $gross,
            'break_minutes'       => $breakForReport,
            'regular_minutes'     => $regular,
            'overtime_minutes'    => $overtime,
            'late_minutes'        => $late,
            'late_count'          => $late > 0 ? 1 : 0,
            'early_leave_minutes' => $earlyLeave,
            'early_leave_count'   => $earlyLeave > 0 ? 1 : 0,
            'variance_minutes'    => $presentWeight > 0 || $leaveWeight > 0 ? $worked - $scheduled : 0,
            'is_missing_checkout' => $missingCheckout,
            'is_estimated'        => $isEstimated,
            'present_weight'      => $presentWeight,
            'absent_weight'       => $absentWeight,
            'leave_weight'        => $leaveWeight,
            'weekend_present'     => $weekendPresent,
            'holiday_present'     => $holidayPresent,
        ];
    }

    protected function breakMinutes(?Calendar $calendar, int $gross, int $span, int $explicitBreak, int $scheduled, int $scheduledBreak): int
    {
        if (! $calendar) {
            return $explicitBreak;
        }

        $policy = $calendar->break_policy ?? BreakPolicy::AutoDeduct;
        $trigger = max(0, (int) ($calendar->break_trigger_minutes ?? 300));

        if ($explicitBreak > 0) {
            return $explicitBreak;
        }

        if ($policy === BreakPolicy::PunchBased) {
            return 0;
        }

        if ($policy === BreakPolicy::AllowSkipForEarlyExit && $scheduled > 0 && $span >= $scheduled) {
            return 0;
        }

        return $gross >= $trigger ? $scheduledBreak : 0;
    }

    protected function leaveMinutes(Leave $leave): int
    {
        if (! (bool) $leave->request_unit_hours) {
            return 0;
        }

        if ($leave->number_of_hours !== null) {
            return max(0, (int) round(((float) $leave->number_of_hours) * 60));
        }

        $from = $this->clockMinutes($leave->request_hour_from);
        $to = $this->clockMinutes($leave->request_hour_to);

        if ($from === null || $to === null) {
            return 0;
        }

        return max(0, $to - $from);
    }

    protected function clockMinutes(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(CalendarAttendance::minutesOfDay($value));
    }

    protected function round(int $minutes, ?Calendar $calendar): int
    {
        $mode = AttendanceSettings::roundingMode();

        if ($mode === RoundingMode::None) {
            return $minutes;
        }

        $step = $mode->step();

        return (int) (round($minutes / $step) * $step);
    }

    protected function timezoneFor(Employee $employee, ?Calendar $calendar): string
    {
        return $employee->time_zone ?: ($calendar?->timezone ?: config('app.timezone'));
    }
}
