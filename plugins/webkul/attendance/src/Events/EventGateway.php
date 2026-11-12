<?php

namespace Webkul\Attendance\Events;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Services\EventPairingService;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Scopes\CompanyScope;

class EventGateway
{
    public static function push(int $employeeId, string $punchedAtUtc, int $direction, string $source, string $sourceRef, ?string $sourceLabel = null): AttendanceEvent
    {
        if (! in_array($direction, [AttendanceEvent::DIRECTION_OUT, AttendanceEvent::DIRECTION_UNKNOWN, AttendanceEvent::DIRECTION_IN], true)) {
            throw new InvalidArgumentException("Invalid attendance direction [{$direction}].");
        }

        $writer = Attendance::sourceWriter($source);

        if ($writer === null || ! WriterRegistry::isRegistered($writer)) {
            throw new InvalidArgumentException("Unknown attendance source [{$source}].");
        }

        $employee = Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($employeeId);

        $event = AttendanceEvent::firstOrCreate(
            [
                'source'     => $source,
                'source_ref' => $sourceRef,
            ],
            [
                'employee_id'  => $employee->getKey(),
                'punched_at'   => $punchedAtUtc,
                'direction'    => $direction,
                'source_label' => $sourceLabel,
                'company_id'   => $employee->company_id,
            ]
        );

        if (! $event->wasRecentlyCreated && (int) $event->employee_id !== (int) $employee->getKey()) {
            $previousEmployee = $event->employee()->withoutGlobalScope(CompanyScope::class)->first();
            $previousAttendance = $event->attendance_id !== null
                ? Attendance::withoutGlobalScope(CompanyScope::class)->find($event->attendance_id)
                : null;
            $writer = Attendance::sourceWriter($source);

            $event->update([
                'employee_id'   => $employee->getKey(),
                'company_id'    => $employee->company_id,
                'attendance_id' => null,
            ]);

            if ($previousEmployee !== null && $previousAttendance !== null && $writer !== null) {
                $anchorDay = $previousAttendance->work_date instanceof Carbon
                    ? $previousAttendance->work_date->copy()
                    : Carbon::parse((string) $previousAttendance->getRawOriginal('work_date'), 'UTC');

                app(EventPairingService::class)->rebuildForRange(
                    $previousEmployee,
                    $writer,
                    $anchorDay->copy()->subDay(),
                    $anchorDay->copy()->addDay(),
                );
            }
        }

        return $event;
    }
}
