<?php

namespace Webkul\Attendance\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Attendance\Filament\Exports\AbsenceReportExporter;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Services\CompanyContext;

class AttendanceOverview extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected string $view = 'attendance::filament.pages.attendance-overview';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    public ?array $filterData = [];

    public ?int $employeeId = null;

    public string $dateFrom;

    public string $dateTo;

    public string $activeTab = 'daily';

    public int $dailyPage = 1;

    public int $dailyPerPage = 25;

    public const DAILY_PER_PAGE_OPTIONS = [10, 25, 50];

    /**
     * Within-request memo for derived data. These props stay out of the
     * Livewire snapshot on purpose: entries for a full range would bloat
     * every roundtrip payload, while filter inputs re-derive them cheaply.
     *
     * @var list<array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}>|null
     */
    private ?array $entriesMemo = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'attendance/overview';
    }

    public static function getNavigationLabel(): string
    {
        return __('attendance::filament/pages/overview.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public function getTitle(): string|Htmlable
    {
        return __('attendance::filament/pages/overview.title');
    }

    /**
     * Shield generates page keys as page_{plugin}_{basename-snake}, so this
     * page owns `page_attendance_attendance_overview` (verified in the
     * permissions table after shield:generate, not assumed).
     */
    protected static function getPagePermission(): ?string
    {
        return 'page_attendance_attendance_overview';
    }

    public function mount(): void
    {
        $this->filterForm->fill([
            'employee_id' => null,
            'date_from'   => Carbon::today()->startOfMonth()->toDateString(),
            'date_to'     => Carbon::today()->toDateString(),
        ]);

        $this->applyFilters();
    }

    protected function getForms(): array
    {
        return [
            'filterForm',
        ];
    }

    public function filterForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filterData')
            ->components([
                Select::make('employee_id')
                    ->label(__('attendance::filament/pages/overview.form.employee'))
                    ->options(fn (): array => Employee::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder(__('attendance::filament/pages/overview.form.all-employees')),
                DatePicker::make('date_from')
                    ->label(__('attendance::filament/pages/overview.form.date-from'))
                    ->required()
                    ->maxDate(Carbon::today()->toDateString()),
                DatePicker::make('date_to')
                    ->label(__('attendance::filament/pages/overview.form.date-to'))
                    ->required()
                    ->afterOrEqual('date_from')
                    ->maxDate(Carbon::today()->toDateString())
                    ->rules([function (Get $get): \Closure {
                        return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $from = $get('date_from');

                            if (! $from || ! $value) {
                                return;
                            }

                            if (AttendanceOverviewService::rangeDays($from, $value) > AttendanceOverviewService::MAX_RANGE_DAYS) {
                                $fail(__('attendance::filament/pages/overview.form.range-too-wide', [
                                    'days' => AttendanceOverviewService::MAX_RANGE_DAYS,
                                ]));
                            }
                        };
                    }]),
            ])
            ->columns(4);
    }

    public function applyFilters(): void
    {
        $data = $this->filterForm->getState();

        $this->employeeId = $data['employee_id'] ?? null;
        $this->dateFrom = $data['date_from'];
        $this->dateTo = $data['date_to'];
        $this->dailyPage = 1;
        $this->entriesMemo = null;
    }

    public function updatedDailyPerPage(): void
    {
        $this->dailyPage = 1;
    }

    public function nextPage(string $pageName = 'page'): void
    {
        $this->dailyPage++;
    }

    public function previousPage(string $pageName = 'page'): void
    {
        $this->dailyPage = max(1, $this->dailyPage - 1);
    }

    public function gotoPage(int $page, string $pageName = 'page'): void
    {
        $this->dailyPage = max(1, $page);
    }

    /**
     * @return list<array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}>
     */
    public function getEntries(): array
    {
        return $this->entriesMemo ??= app(AttendanceOverviewService::class)->daily(
            $this->filteredEmployees(),
            Carbon::parse($this->dateFrom),
            Carbon::parse($this->dateTo),
        )->all();
    }

    /**
     * @return list<string>
     */
    public function getGridDays(): array
    {
        return collect($this->getEntries())->pluck('date')->unique()->sort()->values()->all();
    }

    /**
     * @return array<int, array{name: string, cells: array<string, string>}>
     */
    public function getGrid(): array
    {
        return collect($this->getEntries())->groupBy('employee_id')->map(fn ($rows): array => [
            'name'  => $rows->first()['name'],
            'cells' => $rows->mapWithKeys(fn (array $row): array => [$row['date'] => $this->cellLetter($row['status'])])->all(),
        ])->all();
    }

    /**
     * Daily entries paginated like every other list (25 by default).
     */
    public function getDailyPaginator(): LengthAwarePaginator
    {
        $total = count($this->getEntries());
        $page = $total === 0 ? 1 : min($this->dailyPage, (int) ceil($total / $this->dailyPerPage));

        return new LengthAwarePaginator(
            array_slice($this->getEntries(), ($page - 1) * $this->dailyPerPage, $this->dailyPerPage),
            $total,
            $this->dailyPerPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page'],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('attendance::filament/pages/overview.export'))
                ->icon('heroicon-o-arrow-up-tray')
                ->exporter(AbsenceReportExporter::class)
                ->maxRows(10000)
                ->modifyQueryUsing(function (Builder $query): Builder {
                    $ids = app(CompanyContext::class)->activeIds();

                    return $query
                        ->when($ids !== [], fn (Builder $query) => $query->whereIn('company_id', $ids))
                        ->orderBy('name');
                }),
        ];
    }

    protected function filteredEmployees(): Collection
    {
        return Employee::query()
            ->when($this->employeeId, fn (Builder $query) => $query->whereKey($this->employeeId))
            ->orderBy('name')
            ->get();
    }

    protected function cellLetter(string $status): string
    {
        return match ($status) {
            AttendanceOverviewService::STATUS_ABSENT             => 'A',
            AttendanceOverviewService::STATUS_ON_LEAVE           => 'L',
            AttendanceOverviewService::STATUS_ON_LEAVE_PENDING   => 'P',
            AttendanceOverviewService::STATUS_HOLIDAY            => 'H',
            default                                              => '',
        };
    }

    /**
     * @param  array{status: string, leave_label: ?string, half_day: bool}  $entry
     * @return array{label: string, color: string}
     */
    protected function statusBadge(array $entry): array
    {
        return match ($entry['status']) {
            AttendanceOverviewService::STATUS_PRESENT => [
                'label' => __('attendance::filament/pages/overview.statuses.present'),
                'color' => 'success',
            ],
            AttendanceOverviewService::STATUS_ABSENT => [
                'label' => __('attendance::filament/pages/overview.statuses.absent'),
                'color' => 'danger',
            ],
            AttendanceOverviewService::STATUS_ON_LEAVE => [
                'label' => $entry['leave_label'].($entry['half_day'] ? ' · '.__('attendance::filament/pages/overview.statuses.half-day') : ''),
                'color' => 'info',
            ],
            AttendanceOverviewService::STATUS_ON_LEAVE_PENDING => [
                'label' => $entry['leave_label'].' · '.__('attendance::filament/pages/overview.statuses.pending'),
                'color' => 'warning',
            ],
            default => [
                'label' => (string) $entry['leave_label'],
                'color' => 'gray',
            ],
        };
    }
}
