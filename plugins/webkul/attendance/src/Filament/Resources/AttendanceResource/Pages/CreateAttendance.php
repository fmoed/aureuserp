<?php

namespace Webkul\Attendance\Filament\Resources\AttendanceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Schemas\AttendanceForm;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;

    /**
     * Manual wall-clock input belongs to the employee's day. Normalize to
     * UTC (the storage convention) before writing, exactly like the
     * biometric sync does for device wall clocks. The source is always
     * 'manual' here: the form field is not dehydrated so a forged
     * 'biometric-attendance' value can never reach the database.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $timezone = AttendanceForm::employeeTimezone(
            isset($data['employee_id']) ? (int) $data['employee_id'] : null,
        );

        foreach (['check_in', 'check_out'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = Carbon::parse($data[$field], $timezone)->setTimezone('UTC')->format('Y-m-d H:i:s');
            }
        }

        $data['source'] = 'manual';

        return $data;
    }
}
