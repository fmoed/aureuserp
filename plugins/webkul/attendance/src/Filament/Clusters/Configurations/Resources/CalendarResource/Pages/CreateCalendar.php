<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource;

class CreateCalendar extends CreateRecord
{
    protected static string $resource = CalendarResource::class;

    public function getSubNavigation(): array
    {
        if (filled($cluster = static::getCluster())) {
            return $this->generateNavigationItems($cluster::getClusteredComponents());
        }

        return [];
    }

    protected function getCreatedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title(__('support::filament/resources/calendar/pages/create-calendar.notification.title'))
            ->body(__('support::filament/resources/calendar/pages/create-calendar.notification.body'));
    }
}
