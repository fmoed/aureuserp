<?php

namespace Webkul\RemoteAttendance\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Tables\AttendancesTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\RemoteAttendance\Filament\Widgets\RemoteCheckInWidget;
use Webkul\RemoteAttendance\Services\RemoteCheckInService;
use Webkul\Support\Enums\NavigationGroup;

class RemoteCheckIn extends Page implements HasForms, HasTable
{
    use HasPageShield;
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $view = 'remote-attendance::filament.pages.remote-check-in';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-computer-desktop';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 5;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'attendance/remote-check-in';
    }

    public static function getNavigationLabel(): string
    {
        return __('remote-attendance::filament/pages/remote-check-in.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public function getTitle(): string|Htmlable
    {
        return __('remote-attendance::filament/pages/remote-check-in.title');
    }

    protected static function getPagePermission(): ?string
    {
        return 'page_remote_attendance_remote_check_in';
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()?->user();
        $permission = static::getPagePermission();

        return ($user && $permission && $user->can($permission))
            || RemoteCheckInWidget::canView();
    }

    protected function getHeaderWidgets(): array
    {
        return RemoteCheckInWidget::canView()
            ? [RemoteCheckInWidget::class]
            : [];
    }

    #[On('remote-attendance-updated')]
    public function refreshAttendanceList(): void
    {
        // Livewire re-renders the table automatically after this listener fires.
    }

    public function table(Table $table): Table
    {
        $employeeId = RemoteCheckInWidget::resolveAuthenticatedEmployee()?->getKey() ?? 0;

        return $table
            ->query(
                Attendance::query()
                    ->where('employee_id', $employeeId)
                    ->with(['employee', 'messages'])
            )
            ->defaultSort('work_date', 'desc')
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('work_date')
                    ->label(__('remote-attendance::filament/pages/remote-check-in.table.work-date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('check_in')
                    ->label(__('remote-attendance::filament/pages/remote-check-in.table.check-in'))
                    ->state(fn (Attendance $record): ?Carbon => AttendancesTable::employeeWallClock($record, 'check_in'))
                    ->dateTime('H:i')
                    ->sortable(),
                TextColumn::make('check_out')
                    ->label(__('remote-attendance::filament/pages/remote-check-in.table.check-out'))
                    ->state(fn (Attendance $record): ?Carbon => AttendancesTable::employeeWallClock($record, 'check_out'))
                    ->dateTime('H:i')
                    ->placeholder(__('remote-attendance::filament/pages/remote-check-in.table.no-checkout')),
                TextColumn::make('source')
                    ->label(__('remote-attendance::filament/pages/remote-check-in.table.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, Attendance $record): string => Attendance::sourceDisplayName($state, $record->source_label))
                    ->color(fn (string $state): string => $state === RemoteCheckInService::SOURCE_REMOTE ? 'info' : 'gray'),
                TextColumn::make('accomplishments')
                    ->label(__('remote-attendance::filament/pages/remote-check-in.table.accomplishments'))
                    ->html()
                    ->state(fn (Attendance $record): ?string => RemoteCheckInService::formatAttendanceNotesHtml($record))
                    ->wrap()
                    ->placeholder('—'),
            ]);
    }
}
