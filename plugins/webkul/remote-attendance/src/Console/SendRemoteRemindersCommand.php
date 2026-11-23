<?php

namespace Webkul\RemoteAttendance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\RemoteAttendance\Notifications\RemoteCheckInReminderNotification;
use Webkul\RemoteAttendance\Services\RemoteCheckInService;
use Webkul\RemoteAttendance\Settings\RemoteAttendanceSettings;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\TimeOff\Enums\LeaveValidationType;
use Webkul\TimeOff\Enums\State as LeaveState;
use Webkul\TimeOff\Models\Leave;

class SendRemoteRemindersCommand extends Command
{
    protected $signature = 'remote-attendance:send-reminders {--force : Ignore the reminder time-of-day window (still skips employees already checked in or already reminded today)}';

    protected $description = 'Send morning remote check-in reminders to eligible remote employees who have not checked in today.';

    public function handle(RemoteCheckInService $service, AttendanceOverviewService $overview): int
    {
        if (! RemoteAttendanceSettings::reminderEnabled()) {
            $this->info('Remote attendance reminders are disabled.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('remote-attendance:send-reminders', 300);

        if (! $lock->get()) {
            $this->warn('Reminder run already in progress.');

            return self::SUCCESS;
        }

        try {
            $employees = RemoteCheckInService::applyEligibilityScope(
                Employee::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->with(['user', 'workLocation', 'calendar.attendance', 'company.partner'])
            )->get();

            $sent = 0;
            $skipped = 0;
            $nowUtc = Carbon::now('UTC');
            $reminderTime = RemoteAttendanceSettings::reminderTime();
            $force = (bool) $this->option('force');

            foreach ($employees as $employee) {
                $timezone = $service->employeeTimezone($employee);
                $localNow = $nowUtc->copy()->setTimezone($timezone);
                $localToday = $localNow->toDateString();

                if (! $force && ! $this->withinReminderWindow($localNow, $reminderTime)) {
                    $skipped++;

                    continue;
                }

                $cacheKey = "remote-attendance:reminded:{$employee->getKey()}:{$localToday}";

                if (Cache::has($cacheKey)) {
                    $skipped++;

                    continue;
                }

                if ($this->alreadyCheckedIn($employee, $localToday)) {
                    $skipped++;

                    continue;
                }

                if (! $this->isScheduledToWorkToday($overview, $employee, $localToday)) {
                    $skipped++;

                    continue;
                }

                if (! $this->dispatchReminder($employee, $localToday)) {
                    $skipped++;

                    continue;
                }

                Cache::put($cacheKey, true, now()->addHours(24));
                $sent++;
            }

            $this->info("Remote check-in reminders — sent: {$sent}, skipped: {$skipped}");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    protected function withinReminderWindow(Carbon $localNow, string $reminderTime): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', $reminderTime));

        $windowStart = $localNow->copy()->setTime($hour, $minute, 0);
        $windowEnd = $windowStart->copy()->addHours(2);

        return $localNow->gte($windowStart) && $localNow->lt($windowEnd);
    }

    protected function alreadyCheckedIn(Employee $employee, string $workDate): bool
    {
        return Attendance::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', $workDate)
            ->exists();
    }

    protected function isScheduledToWorkToday(AttendanceOverviewService $overview, Employee $employee, string $workDate): bool
    {
        $date = Carbon::parse($workDate);
        $entry = $overview->daily(collect([$employee]), $date, $date)->first();

        if ($entry !== null) {
            return in_array($entry['status'], [
                AttendanceOverviewService::STATUS_ABSENT,
                AttendanceOverviewService::STATUS_ON_LEAVE_PENDING,
            ], true);
        }

        $calendar = $overview->calendarFor($employee);

        if ($calendar === null || $calendar->shift_type !== ShiftType::Open) {
            return false;
        }

        return ! $this->hasApprovedLeave($employee, $workDate);
    }

    protected function hasApprovedLeave(Employee $employee, string $workDate): bool
    {
        if (! Package::isPluginInstalled('time-off') || ! Schema::hasTable('time_off_leaves')) {
            return false;
        }

        return Leave::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('employee_id', $employee->getKey())
            ->where('state', '!=', LeaveState::REFUSE->value)
            ->where(function ($query): void {
                $query->where('state', LeaveState::VALIDATE_TWO->value)
                    ->orWhereHas('holidayStatus', fn ($type) => $type->where('leave_validation_type', LeaveValidationType::NO_VALIDATION->value));
            })
            ->where(function ($query) use ($workDate): void {
                $query->whereDate('date_from', '<=', $workDate)
                    ->whereDate('date_to', '>=', $workDate);
            })
            ->exists();
    }

    protected function dispatchReminder(Employee $employee, string $workDate): bool
    {
        $company = $employee->company;

        $notification = new RemoteCheckInReminderNotification(
            employeeName: $employee->name,
            workDate: $workDate,
            companyName: $company?->name,
            companyLogo: $company?->logo ?: $company?->partner?->avatar,
            companyEmail: $company?->email,
        );

        if ($employee->user !== null) {
            $employee->user->notify($notification);

            return true;
        }

        if (filled($employee->work_email)) {
            Notification::route('mail', $employee->work_email)->notify($notification);

            return true;
        }

        return false;
    }
}
