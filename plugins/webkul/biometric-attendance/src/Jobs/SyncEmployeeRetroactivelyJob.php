<?php

namespace Webkul\BiometricAttendance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\BiometricAttendance\Services\SyncPipeline;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Scopes\CompanyScope;

class SyncEmployeeRetroactivelyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $uniqueFor = 120;

    public function __construct(public int $employeeId) {}

    public function uniqueId(): string
    {
        return 'biometric:retroactive-employee:'.$this->employeeId;
    }

    public function handle(SyncPipeline $pipeline): void
    {
        $employee = Employee::withoutGlobalScope(CompanyScope::class)->find($this->employeeId);

        if ($employee === null || ! filled($employee->barcode)) {
            return;
        }

        $pipeline->syncEmployeeRetroactively($employee);
    }
}
