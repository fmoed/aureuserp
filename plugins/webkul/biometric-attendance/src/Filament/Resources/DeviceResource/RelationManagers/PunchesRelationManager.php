<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\DeviceSyncService;
use Webkul\BiometricAttendance\Services\SyncPipeline;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Raw punches of one device, including the punches whose device user id is
 * not bound to an employee yet, with a one-click binding that also runs
 * the retroactive backfill.
 */
class PunchesRelationManager extends RelationManager
{
    protected static string $relationship = 'punches';

    protected static ?string $recordTitleAttribute = 'zk_user_id';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('biometric-attendance::filament/resources/device/relation-managers/punches.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->punches()->whereNull('employee_id')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee'))
            ->columns([
                TextColumn::make('zk_user_id')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.user-id'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employee.name')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.employee'))
                    ->placeholder(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.unmatched'))
                    ->searchable()
                    ->badge()
                    ->color(fn (Punch $record): string => $record->employee_id === null ? 'warning' : 'success'),
                TextColumn::make('punch_time')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.punch-time'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('raw_type')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.raw-type'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pushed_at')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.pushed-at'))
                    ->dateTime()
                    ->placeholder(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.pending'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('match_status')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.filters.match-status'))
                    ->options([
                        'unmatched' => __('biometric-attendance::filament/resources/device/relation-managers/punches.table.filters.unmatched'),
                        'matched'   => __('biometric-attendance::filament/resources/device/relation-managers/punches.table.filters.matched'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'unmatched' => $query->whereNull('employee_id'),
                            'matched'   => $query->whereNotNull('employee_id'),
                            default     => $query,
                        };
                    }),
                SelectFilter::make('push_status')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.filters.push-status'))
                    ->options([
                        'pending' => __('biometric-attendance::filament/resources/device/relation-managers/punches.table.columns.pending'),
                        'pushed'  => __('biometric-attendance::filament/resources/device/relation-managers/punches.table.filters.pushed'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'pending' => $query->whereNull('pushed_at'),
                            'pushed'  => $query->whereNotNull('pushed_at'),
                            default   => $query,
                        };
                    }),
            ])
            ->headerActions([
                Action::make('rematchUnmatched')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.actions.rematch.label'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn (): bool => DeviceResource::canEdit($this->getOwnerRecord()))
                    ->action(function (): void {
                        /** @var Device $device */
                        $device = $this->getOwnerRecord();

                        $backfilled = app(DeviceSyncService::class)->backfillUnmatched($device);

                        app(SyncPipeline::class)->pushPendingPunches($device);

                        app(SyncPipeline::class)->pairAttendance();

                        Notification::make()
                            ->success()
                            ->title(__('biometric-attendance::filament/resources/device/relation-managers/punches.actions.rematch.completed', [
                                'count' => $backfilled,
                            ]))
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('assignEmployee')
                    ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.actions.assign.label'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (Punch $record): bool => $record->employee_id === null)
                    ->authorize(fn (): bool => DeviceResource::canEdit($this->getOwnerRecord())
                        && (bool) auth()->user()?->can('update_employee_employee'))
                    ->schema([
                        Select::make('employee_id')
                            ->label(__('biometric-attendance::filament/resources/device/relation-managers/punches.actions.assign.employee'))
                            ->options(fn (PunchesRelationManager $livewire): array => $livewire->getEmployeeOptions())
                            ->getSearchResultsUsing(fn (PunchesRelationManager $livewire, string $search): array => $livewire->getEmployeeOptions($search))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Punch $record, array $data): void {
                        /** @var Device $device */
                        $device = $this->getOwnerRecord();

                        $employeeQuery = AttendancePolicy::scopeEmployeeQuery(Employee::query());

                        if ($device->company_id !== null) {
                            $employeeQuery->where(function (Builder $q) use ($device): void {
                                $q->where('company_id', $device->company_id)
                                    ->orWhereNull('company_id');
                            });
                        }

                        $employee = $employeeQuery->find($data['employee_id'] ?? null);

                        if ($employee === null) {
                            abort(403);
                        }

                        Gate::authorize('update', $employee);

                        $barcode = trim((string) $record->zk_user_id);
                        $normalizedBarcode = DeviceSyncService::normalizeUserId($barcode);

                        $conflictQuery = Employee::withoutGlobalScope(CompanyScope::class)
                            ->whereNotNull('barcode')
                            ->where('barcode', '!=', '')
                            ->whereKeyNot($employee->getKey());

                        if ($device->company_id !== null) {
                            $conflictQuery->where(function (Builder $q) use ($device): void {
                                $q->where('company_id', $device->company_id)
                                    ->orWhereNull('company_id');
                            });
                        }

                        $conflictEmployee = $conflictQuery
                            ->get(['id', 'name', 'barcode'])
                            ->first(fn (Employee $candidate): bool => DeviceSyncService::normalizeUserId((string) $candidate->barcode) === $normalizedBarcode);

                        if ($conflictEmployee !== null) {
                            Notification::make()
                                ->danger()
                                ->title(__('biometric-attendance::filament/resources/device/relation-managers/punches.actions.assign.conflict', [
                                    'barcode' => $barcode,
                                    'name'    => $conflictEmployee->name,
                                ]))
                                ->send();

                            return;
                        }

                        $employee->barcode = $barcode;
                        $backfilled = app(DeviceSyncService::class)->backfillForEmployee($employee);
                        $employee->save();

                        Notification::make()
                            ->success()
                            ->title(__('biometric-attendance::filament/resources/device/relation-managers/punches.actions.assign.assigned', [
                                'name'  => $employee->name,
                                'count' => $backfilled,
                            ]))
                            ->send();
                    }),
            ])
            ->defaultSort('punch_time', 'desc')
            ->emptyStateIcon('heroicon-o-finger-print')
            ->emptyStateHeading(__('biometric-attendance::filament/resources/device/relation-managers/punches.table.empty'));
    }

    public function getEmployeeOptions(?string $search = null): array
    {
        /** @var Device $device */
        $device = $this->getOwnerRecord();

        return static::employeeOptions($search, $device);
    }

    /**
     * @return array<int, string>
     */
    protected static function employeeOptions(?string $search = null, ?Device $device = null): array
    {
        $query = Employee::query();

        if ($device?->company_id !== null) {
            $query->where(function (Builder $q) use ($device): void {
                $q->where('company_id', $device->company_id)
                    ->orWhereNull('company_id');
            });
        }

        $query = AttendancePolicy::scopeEmployeeQuery($query);

        return $query
            ->when(filled($search), fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }
}
