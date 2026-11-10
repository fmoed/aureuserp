<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages;

use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource;
use Webkul\Support\Filament\Resources\CalendarResource\Pages\ListCalendars as BaseListCalendars;

class ListCalendars extends BaseListCalendars
{
    protected static string $resource = CalendarResource::class;
}
