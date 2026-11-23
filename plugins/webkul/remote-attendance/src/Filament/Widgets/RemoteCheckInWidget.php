<?php

namespace Webkul\RemoteAttendance\Filament\Widgets;

use DomainException;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Tables\AttendancesTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Package;
use Webkul\Project\Enums\TaskState;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\Task;
use Webkul\RemoteAttendance\Services\RemoteCheckInService;
use Webkul\RemoteAttendance\Settings\RemoteAttendanceSettings;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

class RemoteCheckInWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'remote-attendance::filament.widgets.remote-check-in-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    private ?Employee $resolvedEmployee = null;

    private bool $employeeLookedUp = false;

    public static function canView(): bool
    {
        return static::isUserEligible(Auth::user());
    }

    public static function isUserEligible(?User $user = null): bool
    {
        if (! $user || ! Package::isPluginInstalled('remote-attendance')) {
            return false;
        }

        $employee = static::resolveAuthenticatedEmployee($user);

        if (! $employee) {
            return false;
        }

        return app(RemoteCheckInService::class)->isEligible($employee);
    }

    public static function resolveAuthenticatedEmployee(?User $user = null): ?Employee
    {
        $userId = $user?->getKey() ?? Auth::id();

        if (! $userId) {
            return null;
        }

        return Employee::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with([
                'workLocation' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class),
                'calendar'     => fn ($query) => $query->withoutGlobalScope(CompanyScope::class),
            ])
            ->where('user_id', $userId)
            ->first();
    }

    public function currentEmployee(): ?Employee
    {
        if ($this->employeeLookedUp) {
            return $this->resolvedEmployee;
        }

        $this->employeeLookedUp = true;

        return $this->resolvedEmployee = static::resolveAuthenticatedEmployee();
    }

    public function todayAttendance(): ?Attendance
    {
        $employee = $this->currentEmployee();

        if (! $employee) {
            return null;
        }

        $attendance = app(RemoteCheckInService::class)->todayAttendance($employee);
        $attendance?->loadMissing('messages');

        return $attendance;
    }

    public function todayCheckInFormatted(): ?string
    {
        $attendance = $this->todayAttendance();

        if (! $attendance) {
            return null;
        }

        return AttendancesTable::employeeWallClock($attendance, 'check_in')?->format('H:i');
    }

    public function todayCheckOutFormatted(): ?string
    {
        $attendance = $this->todayAttendance();

        if (! $attendance || $attendance->check_out === null) {
            return null;
        }

        return AttendancesTable::employeeWallClock($attendance, 'check_out')?->format('H:i');
    }

    /**
     * @return Collection<int, mixed>
     */
    public function todayAccomplishments(): Collection
    {
        $attendance = $this->todayAttendance();

        if (! $attendance) {
            return collect();
        }

        return $attendance->messages
            ->where('type', 'comment')
            ->sortBy('id')
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public static function projectOptions(): array
    {
        if (! RemoteCheckInService::projectsAvailable()) {
            return [];
        }

        return once(fn (): array => Project::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->pluck('name', 'id')
            ->all());
    }

    /**
     * @return array<int, string>
     */
    public static function taskOptions(?int $projectId): array
    {
        if (! RemoteCheckInService::projectsAvailable()) {
            return [];
        }

        $userId = Auth::id();

        return once(function () use ($projectId, $userId): array {
            $query = Task::query()
                ->where('is_active', true)
                ->orderBy('title')
                ->limit(100);

            if ($projectId !== null) {
                $query->where('project_id', $projectId);
            } elseif ($userId !== null) {
                $query->whereHas('users', fn (Builder $users) => $users->whereKey($userId));
            }

            return $query->pluck('title', 'id')->all();
        });
    }

    public function checkInAction(): Action
    {
        return Action::make('checkIn')
            ->label(__('remote-attendance::filament/pages/remote-check-in.actions.check-in.label'))
            ->icon('heroicon-o-arrow-right-end-on-rectangle')
            ->color('success')
            ->visible(fn (): bool => ($employee = $this->currentEmployee()) !== null
                && app(RemoteCheckInService::class)->isEligible($employee)
                && $this->todayAttendance() === null)
            ->schema([
                Textarea::make('plan_note')
                    ->label(__('remote-attendance::filament/pages/remote-check-in.actions.check-in.plan-note'))
                    ->placeholder(__('remote-attendance::filament/pages/remote-check-in.actions.check-in.plan-placeholder'))
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $employee = $this->currentEmployee();

                if (! $employee) {
                    return;
                }

                try {
                    app(RemoteCheckInService::class)->checkIn($employee, $data['plan_note'] ?? null);

                    Notification::make()
                        ->success()
                        ->title(__('remote-attendance::filament/pages/remote-check-in.actions.check-in.success'))
                        ->send();

                    $this->dispatch('remote-attendance-updated');
                } catch (DomainException $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->send();
                }
            });
    }

    public function checkOutAction(): Action
    {
        return Action::make('checkOut')
            ->label(__('remote-attendance::filament/pages/remote-check-in.actions.check-out.label'))
            ->icon('heroicon-o-arrow-left-start-on-rectangle')
            ->color('warning')
            ->visible(function (): bool {
                if (! RemoteAttendanceSettings::requireCheckout()) {
                    return false;
                }

                $employee = $this->currentEmployee();

                if (! $employee || ! app(RemoteCheckInService::class)->isEligible($employee)) {
                    return false;
                }

                $today = $this->todayAttendance();

                return $today !== null && $today->source === RemoteCheckInService::SOURCE_REMOTE && $today->check_out === null;
            })
            ->schema([
                Textarea::make('summary_note')
                    ->label(__('remote-attendance::filament/pages/remote-check-in.actions.check-out.summary-note'))
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $employee = $this->currentEmployee();

                if (! $employee) {
                    return;
                }

                try {
                    app(RemoteCheckInService::class)->checkOut($employee, $data['summary_note'] ?? null);

                    Notification::make()
                        ->success()
                        ->title(__('remote-attendance::filament/pages/remote-check-in.actions.check-out.success'))
                        ->send();

                    $this->dispatch('remote-attendance-updated');
                } catch (DomainException $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->send();
                }
            });
    }

    public function logWorkAction(): Action
    {
        return Action::make('logWork')
            ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.label'))
            ->icon('heroicon-o-clipboard-document-check')
            ->color('primary')
            ->visible(fn (): bool => $this->todayAttendance() !== null)
            ->modalWidth('2xl')
            ->schema(fn (): array => static::logWorkSchema())
            ->action(function (array $data): void {
                $employee = $this->currentEmployee();

                if (! $employee) {
                    return;
                }

                try {
                    app(RemoteCheckInService::class)->logDailyWork($employee, $data);

                    Notification::make()
                        ->success()
                        ->title(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.success'))
                        ->send();

                    $this->dispatch('remote-attendance-updated');
                } catch (DomainException $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->send();
                }
            });
    }

    /**
     * @return list<Component>
     */
    public static function logWorkSchema(): array
    {
        $itemFields = [
            Textarea::make('description')
                ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.description'))
                ->placeholder(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.description-placeholder'))
                ->required()
                ->rows(2)
                ->columnSpanFull(),
        ];

        if (RemoteCheckInService::projectsAvailable()) {
            $itemFields[] = Select::make('project_id')
                ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.project'))
                ->options(static::projectOptions(...))
                ->searchable()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('task_id', null));

            $itemFields[] = Select::make('task_id')
                ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.task'))
                ->options(fn (Get $get): array => static::taskOptions($get('project_id') ? (int) $get('project_id') : null))
                ->searchable()
                ->live();

            $itemFields[] = TextInput::make('new_task_title')
                ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.new-task'))
                ->visible(fn (Get $get): bool => filled($get('project_id')) && blank($get('task_id')))
                ->live(onBlur: true)
                ->maxLength(255);

            $itemFields[] = Select::make('task_state')
                ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.task-state'))
                ->options(TaskState::options())
                ->visible(fn (Get $get): bool => filled($get('task_id')) || filled($get('new_task_title')));

            $itemFields[] = TextInput::make('unit_amount')
                ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.hours-spent'))
                ->numeric()
                ->minValue(0)
                ->maxValue(24)
                ->step(0.25)
                ->visible(fn (Get $get): bool => filled($get('project_id')));
        }

        return [
            Repeater::make('items')
                ->label(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.items-label'))
                ->addActionLabel(__('remote-attendance::filament/pages/remote-check-in.actions.log-work.add-item'))
                ->schema($itemFields)
                ->columns(2)
                ->defaultItems(1)
                ->minItems(1)
                ->reorderable(false),
        ];
    }
}
