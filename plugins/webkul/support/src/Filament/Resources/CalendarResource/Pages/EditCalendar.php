<?php

namespace Webkul\Support\Filament\Resources\CalendarResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;
use Webkul\Support\Filament\Resources\CalendarResource;
use Webkul\Support\Filament\Resources\CalendarResource\Schemas\CalendarForm;

class EditCalendar extends EditRecord
{
    protected static string $resource = CalendarResource::class;

    protected function afterSave(): void
    {
        CalendarForm::syncSchedulePeriods($this->getRecord(), $this->form->getRawState());
    }

    #[On('refreshCalendarSummaryHours')]
    public function refreshSummaryHoursFromRelation(): void
    {
        $this->record->refresh();
        $this->data['hours_per_day'] = $this->record->hours_per_day;
        $this->data['full_time_required_hours'] = $this->record->full_time_required_hours;
    }

    public function getSubNavigation(): array
    {
        if (filled($cluster = static::getCluster())) {
            return $this->generateNavigationItems($cluster::getClusteredComponents());
        }

        return [];
    }

    protected function getSavedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title(__('support::filament/resources/calendar/pages/edit-calendar.notification.title'))
            ->body(__('support::filament/resources/calendar/pages/edit-calendar.notification.body'));
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('support::filament/resources/calendar/pages/edit-calendar.header-actions.delete.notification.title'))
                        ->body(__('support::filament/resources/calendar/pages/edit-calendar.header-actions.delete.notification.body')),
                ),
        ];
    }
}
