<?php

namespace Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Webkul\Support\Models\CalendarLeave;

/**
 * Public holidays that apply to this shift: its own entries plus the
 * company-wide ones. Company-wide holidays stay read-only here; adding a
 * holiday through this manager binds it to the shift.
 */
class PublicHolidaysRelationManager extends RelationManager
{
    protected static string $relationship = 'publicHolidays';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->publicHolidays()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('calendar_id', $this->getOwnerRecord()->getKey())
                    ->orWhereNull('calendar_id')))
            ->columns([
                TextColumn::make('name')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.table.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.table.columns.scope'))
                    ->badge()
                    ->state(fn (CalendarLeave $record): string => $record->calendar_id === null
                        ? __('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.scopes.company')
                        : __('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.scopes.shift'))
                    ->color(fn (CalendarLeave $record): string => $record->calendar_id === null ? 'gray' : 'primary'),
                TextColumn::make('date_from')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.table.columns.date-from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('date_to')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.table.columns.date-to'))
                    ->date()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.actions.create.label'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.table.columns.name'))
                            ->required()
                            ->maxLength(255),
                        DatePicker::make('date_from')
                            ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.table.columns.date-from'))
                            ->native(false)
                            ->required(),
                        DatePicker::make('date_to')
                            ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.table.columns.date-to'))
                            ->native(false)
                            ->required()
                            ->afterOrEqual('date_from'),
                    ])
                    ->mutateDataUsing(fn (array $data): array => array_merge($data, ['time_type' => 'leave'])),
                Action::make('assignExistingHoliday')
                    ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.actions.assign-existing.label'))
                    ->icon('heroicon-o-link')
                    ->schema([
                        Select::make('holiday_id')
                            ->label(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.actions.assign-existing.holiday'))
                            ->options(function (PublicHolidaysRelationManager $livewire): array {
                                $calendar = $livewire->getOwnerRecord();

                                $query = CalendarLeave::query()
                                    ->where('time_type', 'leave')
                                    ->whereNull('calendar_id')
                                    ->whereNull('resource_id');

                                if ($calendar->company_id !== null) {
                                    $query->where(function (Builder $q) use ($calendar): void {
                                        $q->where('company_id', $calendar->company_id)
                                            ->orWhereNull('company_id');
                                    });
                                }

                                return $query
                                    ->orderBy('date_from', 'desc')
                                    ->limit(100)
                                    ->get()
                                    ->mapWithKeys(function (CalendarLeave $leave): array {
                                        $from = $leave->date_from ? Carbon::parse($leave->date_from)->toDateString() : '';
                                        $to = $leave->date_to ? Carbon::parse($leave->date_to)->toDateString() : '';
                                        $range = $from === $to ? $from : "{$from} ~ {$to}";
                                        $label = $range ? "{$leave->name} ({$range})" : $leave->name;

                                        return [$leave->id => $label];
                                    })
                                    ->all();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $holiday = CalendarLeave::query()->find($data['holiday_id']);

                        if ($holiday === null) {
                            return;
                        }

                        CalendarLeave::firstOrCreate([
                            'calendar_id' => $this->getOwnerRecord()->getKey(),
                            'name'        => $holiday->name,
                            'date_from'   => $holiday->date_from,
                            'date_to'     => $holiday->date_to,
                        ], [
                            'time_type'   => 'leave',
                            'company_id'  => $holiday->company_id ?? $this->getOwnerRecord()->company_id,
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('attendance::filament/clusters/configurations/resources/calendar/relation-managers/public-holidays.actions.assign-existing.assigned'))
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (CalendarLeave $record): bool => $record->calendar_id !== null),
                DeleteAction::make()
                    ->visible(fn (CalendarLeave $record): bool => $record->calendar_id !== null),
            ])
            ->defaultSort('date_from', 'desc');
    }
}
