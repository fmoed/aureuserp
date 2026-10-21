<?php

namespace Webkul\Attendance\Filament\Resources\AttendanceEventResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource\Tables\AttendanceEventsTable;
use Webkul\Attendance\Services\AttendanceOverviewService;

class ListAttendanceEvents extends ListRecords
{
    protected static string $resource = AttendanceEventResource::class;

    public function updatedTableFilters(): void
    {
        parent::updatedTableFilters();

        $data = $this->tableFilters['punched_at'] ?? [];

        if (AttendanceEventsTable::rangeIsOversized($data)) {
            throw ValidationException::withMessages([
                'tableFilters.punched_at.punched_to' => __('attendance::filament/resources/attendance-event.table.filters.range-too-wide', [
                    'days' => AttendanceOverviewService::MAX_RANGE_DAYS,
                ]),
            ]);
        }

        $this->resetErrorBag('tableFilters.punched_at.punched_to');
    }
}
