<?php

namespace Webkul\Attendance\Filament\Resources\AttendanceResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Schemas\AttendanceForm;
use Webkul\Attendance\Models\Attendance;
use Webkul\Chatter\Filament\Actions\ChatterAction;

class EditAttendance extends EditRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ChatterAction::make()
                ->resource(static::$resource)
                ->activityPlans($this->getRecord()->activityPlans()),
        ];
    }

    /**
     * Stored values are UTC; show them back on the employee's wall clock.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $timezone = AttendanceForm::employeeTimezone(
            isset($data['employee_id']) ? (int) $data['employee_id'] : null,
        );

        foreach (['check_in', 'check_out'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = Carbon::parse($data[$field], 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    /**
     * The row identity (employee + work day) is frozen: the fields are
     * disabled in the form, and any forged change is dropped here so the
     * stored times can never be silently re-based onto another zone.
     *
     * Device rows also freeze their check-in: the punch time is the raw
     * source of truth, so only a missing check-out may be filled in.
     *
     * @see CreateAttendance::mutateFormDataBeforeCreate()
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        $data['employee_id'] = $record->employee_id;
        $data['work_date'] = $record->work_date->format('Y-m-d');

        $timezone = AttendanceForm::employeeTimezone((int) $data['employee_id']);
        $isManual = $record->source === Attendance::SOURCE_MANUAL;

        if ($isManual) {
            if (! empty($data['check_in'])) {
                $data['check_in'] = Carbon::parse($data['check_in'], $timezone)->setTimezone('UTC')->format('Y-m-d H:i:s');
            }
        } else {
            $data['check_in'] = $record->getRawOriginal('check_in');
        }

        if (! empty($data['check_out'])) {
            $data['check_out'] = Carbon::parse($data['check_out'], $timezone)->setTimezone('UTC')->format('Y-m-d H:i:s');
        }

        return $data;
    }
}
