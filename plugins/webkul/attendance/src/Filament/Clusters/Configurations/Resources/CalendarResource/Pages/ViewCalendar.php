<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages;

use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Actions\AssignEmployeesToShiftAction;
use Webkul\Support\Filament\Resources\CalendarResource\Pages\ViewCalendar as BaseViewCalendar;

class ViewCalendar extends BaseViewCalendar
{
    protected static string $resource = CalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AssignEmployeesToShiftAction::make($this->getRecord()),
            ...parent::getHeaderActions(),
        ];
    }
}
