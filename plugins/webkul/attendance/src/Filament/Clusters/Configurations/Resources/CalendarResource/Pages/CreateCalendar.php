<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages;

use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource;
use Webkul\Support\Filament\Resources\CalendarResource\Pages\CreateCalendar as BaseCreateCalendar;

class CreateCalendar extends BaseCreateCalendar
{
    protected static string $resource = CalendarResource::class;
}
