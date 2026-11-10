<?php

namespace Webkul\Support\Filament\Resources\CalendarResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Webkul\Support\Filament\Resources\CalendarResource;
use Webkul\Support\Filament\Resources\CalendarResource\Schemas\CalendarForm;

class CreateCalendar extends CreateRecord
{
    protected static string $resource = CalendarResource::class;

    protected function afterCreate(): void
    {
        CalendarForm::syncSchedulePeriods($this->getRecord(), $this->form->getRawState());
    }

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
