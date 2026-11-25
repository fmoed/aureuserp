<?php

namespace Webkul\Attendance\Jobs;

use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Services\EventPairingService;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\BiometricAttendance\Services\DeviceSyncService;
use Webkul\BiometricAttendance\Services\SyncPipeline;
use Webkul\PluginManager\Package;
use Webkul\Security\Models\User;

class RebuildAttendanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const LOCK_KEY = 'attendance:rebuild-lock';

    public const RUNNING_KEY = 'attendance:rebuild-running';

    public int $timeout = 300;

    public int $uniqueFor = 300;

    /**
     * @param  list<int>|null  $companyIds
     */
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public ?int $calendarId = null,
        public ?int $departmentId = null,
        public ?int $employeeId = null,
        public ?int $initiatorUserId = null,
        public ?array $companyIds = null,
    ) {}

    public static function isRunning(): bool
    {
        return Cache::has(self::RUNNING_KEY);
    }

    public function uniqueId(): string
    {
        return 'attendance:rebuild-job';
    }

    public function handle(EventPairingService $pairing): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (! $lock->get()) {
            return;
        }

        Cache::put(self::RUNNING_KEY, $this->initiatorUserId ?? true, 300);

        $previousUser = Auth::user();
        $user = $this->initiatorUserId !== null
            ? User::query()->find($this->initiatorUserId)
            : null;

        if ($user !== null) {
            Auth::setUser($user);
        }

        try {
            $companyIds = $this->companyIds ?: null;

            if (
                Package::isPluginInstalled('biometric-attendance')
                && class_exists(DeviceSyncService::class)
                && class_exists(SyncPipeline::class)
            ) {
                app(DeviceSyncService::class)->backfillAllUnmatched();
                app(SyncPipeline::class)->pushPendingPunches(companyIds: $companyIds);
            }

            $from = Carbon::parse($this->dateFrom);
            $to = Carbon::parse($this->dateTo);
            $slugs = WriterRegistry::slugs() ?: ['biometric-attendance'];

            $employeesCount = 0;
            $rows = 0;

            AttendanceOverviewService::eligibleEmployeeQuery()
                ->when($companyIds !== null, fn (Builder $query) => $query->whereIn('company_id', $companyIds))
                ->when($this->employeeId !== null, fn (Builder $query) => $query->whereKey($this->employeeId))
                ->when($this->departmentId !== null, fn (Builder $query) => AttendanceOverviewService::scopeByDepartment($query, $this->departmentId))
                ->when($this->calendarId !== null, fn (Builder $query) => AttendanceOverviewService::scopeByCalendar($query, $this->calendarId))
                ->chunkById(25, function ($employees) use (&$employeesCount, &$rows, $pairing, $slugs, $from, $to): void {
                    foreach ($employees as $employee) {
                        $employeesCount++;

                        foreach ($slugs as $slug) {
                            $rows += $pairing->rebuildForRange($employee, $slug, $from, $to)['rows'];
                        }
                    }
                });

            if ($user !== null) {
                Notification::make()
                    ->success()
                    ->title(__('attendance::filament/actions/rebuild-attendance.completed-title'))
                    ->body(__('attendance::filament/actions/rebuild-attendance.completed', [
                        'employees' => $employeesCount,
                        'rows'      => $rows,
                    ]))
                    ->sendToDatabase($user);
            }
        } finally {
            if ($user !== null) {
                $previousUser !== null ? Auth::setUser($previousUser) : Auth::forgetGuards();
            }

            Cache::forget(self::RUNNING_KEY);
            $lock->release();
        }
    }

    public function failed(?Throwable $e = null): void
    {
        Cache::forget(self::RUNNING_KEY);
    }
}
