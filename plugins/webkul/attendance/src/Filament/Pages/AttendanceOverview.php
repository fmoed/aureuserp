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
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Webkul\Attendance\Filament\Exports\AbsenceDetailExporter;
use Webkul\Attendance\Filament\Exports\AbsenceReportExporter;
use Webkul\Attendance\Filament\Exports\Jobs\PrepareAbsenceDetailExport;
use Webkul\Attendance\Filament\Tables\AttendanceRegisterTable;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Attendance register ("سجل التحضير"): one row per employee, one column
 * per calendar day of the applied display range (a whole month in month
 * mode, an arbitrary range in range mode), every day up to today carrying
 * an explicit colored status word. Two ranges are tracked: the display
 * range decides the columns (future days render as a uniform blank block),
 * the data range drives exports and totals and never runs past today.
 */
class AttendanceOverview extends Page implements HasForms, HasTable
{
    use HasPageShield;
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $view = 'attendance::filament.pages.attendance-overview';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    /**
     * Explicit nav order inside the Attendance group (with the events
     * register and the configurations cluster): the daily working tool
     * first, without relying on class-discovery order.
     */
    protected static ?int $navigationSort = 1;

    public ?array $filterData = [];

    #[Locked]
    public ?int $employeeId = null;

    /**
     * Data range: drives exports, summaries and the detail query. Kept at
     * or before today so future days never inflate the figures.
     */
    #[Locked]
    public string $dateFrom;

    #[Locked]
    public string $dateTo;

    /**
     * Display range: decides the table's day columns. In month mode it is
     * the full selected month (weekends and future days included as
     * columns), in range mode it mirrors the picked dates.
     */
    #[Locked]
    public ?string $displayFrom = null;

    #[Locked]
    public ?string $displayTo = null;

    /**
     * Applied view mode (month, range or year). Year mode renders one
     * column per elapsed month instead of per day, so its 366-day display
     * range is exempt from the 93-day register guard by construction.
     */
    #[Locked]
    public string $rangeMode = 'month';

    /**
     * Shared table page sizes (global report limits): whitelist checked
     * against tampered Livewire payloads in updatedTableRecordsPerPage().
     */
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    public const REGISTER_DAYS = 'days';

    public const REGISTER_TOTALS = 'totals';

    /**
     * Which columns the register table shows: the day-by-day cells or the
     * per-employee totals. UI-only state (toggling it changes nothing about
     * the applied ranges), so it stays unlocked. Kept in the URL so a
     * browser refresh restores the active tab instead of falling back to
     * the day cells.
     */
    #[Url]
    public string $registerView = self::REGISTER_DAYS;

    /**
     * Within-request memo for derived data. These props stay out of the
     * Livewire snapshot on purpose: rows for a full month would bloat
     * every roundtrip payload, while filter inputs re-derive them cheaply.
     *
     * @var list<array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}>|null
     */
    private ?array $entriesMemo = null;

    /**
     * Within-request memo for register rows (cells + totals per employee).
     *
     * @var list<array{employee_id: int, name: string, calendar: ?string, cells: array<string, string>, totals: array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}}>|null
     */
    private ?array $registerMemo = null;

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
        // The tab arrives via the URL (#[Url] restores it before mount),
        // so a tampered query value must fall back instead of being trusted.
        if (! in_array($this->registerView, [self::REGISTER_DAYS, self::REGISTER_TOTALS], true)) {
            $this->registerView = self::REGISTER_DAYS;
        }

        $this->filterForm->fill([
            'employee_id' => null,
            'view_mode'   => 'month',
            'month'       => Carbon::today()->month,
            'year'        => Carbon::today()->year,
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
        $isMonth = fn (Get $get): bool => ($get('view_mode') ?? 'month') === 'month';
        $isRange = fn (Get $get): bool => $get('view_mode') === 'range';
        $isMonthOrYear = fn (Get $get): bool => in_array($get('view_mode') ?? 'month', ['month', 'year'], true);

        // Year mode is summary-only (it forces the totals tab), so its
        // option only makes sense while the totals tab is active. The form
        // is rebuilt on every request, hence on every tab toggle as well.
        $modeOptions = [
            'month' => __('attendance::filament/pages/overview.form.mode-month'),
            'range' => __('attendance::filament/pages/overview.form.mode-range'),
        ];

        if ($this->registerView === self::REGISTER_TOTALS) {
            $modeOptions['year'] = __('attendance::filament/pages/overview.form.mode-year');
        }

        return $schema
            ->statePath('filterData')
            ->components([
                Select::make('employee_id')
                    ->label(__('attendance::filament/pages/overview.form.employee'))
                    ->options(fn (): array => Employee::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder(__('attendance::filament/pages/overview.form.all-employees')),
                Select::make('view_mode')
                    ->label(__('attendance::filament/pages/overview.form.view-mode'))
                    ->options($modeOptions)
                    ->default('month')
                    ->required()
                    ->live(),
                Select::make('month')
                    ->label(__('attendance::filament/pages/overview.form.month'))
                    ->options(function (Get $get): array {
                        $today = Carbon::today();
                        $year = (int) ($get('year') ?? $today->year);

                        return collect(range(1, 12))
                            ->when(
                                $year === $today->year,
                                fn (Collection $months): Collection => $months->filter(fn (int $month): bool => $month <= $today->month),
                            )
                            ->mapWithKeys(fn (int $month): array => [
                                $month => Carbon::create(2000, $month, 1)->locale(app()->getLocale())->isoFormat('MMMM'),
                            ])
                            ->all();
                    })
                    ->required()
                    ->visible($isMonth)
                    ->rules([
                        function (Get $get): \Closure {
                            return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                $today = Carbon::today();
                                $year = (int) ($get('year') ?? $today->year);
                                $month = (int) $value;

                                if ($year > $today->year || ($year === $today->year && $month > $today->month)) {
                                    $fail(__('attendance::filament/pages/overview.form.month-future'));
                                }
                            };
                        },
                    ]),
                Select::make('year')
                    ->label(__('attendance::filament/pages/overview.form.year'))
                    ->options(fn (): array => collect(range(Carbon::today()->year - 2, Carbon::today()->year))
                        ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
                        ->all())
                    ->required()
                    ->live()
                    ->visible($isMonthOrYear)
                    ->helperText(__('attendance::filament/pages/overview.form.year-hint')),
                DatePicker::make('date_from')
                    ->label(__('attendance::filament/pages/overview.form.date-from'))
                    ->required()
                    ->maxDate(Carbon::today()->toDateString())
                    ->visible($isRange)
                    ->helperText(__('attendance::filament/pages/overview.form.range-hint', [
                        'days' => AttendanceOverviewService::MAX_RANGE_DAYS,
                    ])),
                DatePicker::make('date_to')
                    ->label(__('attendance::filament/pages/overview.form.date-to'))
                    ->required()
                    ->afterOrEqual('date_from')
                    ->maxDate(Carbon::today()->toDateString())
                    ->visible($isRange)
                    ->helperText(__('attendance::filament/pages/overview.form.range-hint', [
                        'days' => AttendanceOverviewService::MAX_RANGE_DAYS,
                    ]))
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

        $today = Carbon::today();

        $viewMode = in_array($data['view_mode'] ?? 'month', ['month', 'range', 'year'], true)
            ? $data['view_mode']
            : 'month';

        $this->rangeMode = $viewMode;

        if ($viewMode === 'range') {
            $displayFrom = Carbon::parse($data['date_from']);
            $displayTo = Carbon::parse($data['date_to']);
        } elseif ($viewMode === 'year') {
            $year = min((int) ($data['year'] ?? $today->year), $today->year);
            $displayFrom = Carbon::create($year, 1, 1);
            $displayTo = Carbon::create($year, 12, 31);
        } else {
            $year = (int) ($data['year'] ?? $today->year);
            $month = (int) ($data['month'] ?? $today->month);
            $displayFrom = Carbon::create($year, $month, 1);
            $displayTo = $displayFrom->copy()->endOfMonth();
        }

        $this->displayFrom = $displayFrom->toDateString();
        $this->displayTo = $displayTo->toDateString();
        $this->dateFrom = $displayFrom->toDateString();
        $this->dateTo = (string) min($displayTo->toDateString(), $today->toDateString());

        if ($viewMode === 'year') {
            // Year mode is summary-only: a year of cells is noise, so the
            // register is locked to per-employee totals. Month/range modes
            // never touch the toggle — a deliberate totals choice survives
            // re-applying filters.
            $this->registerView = self::REGISTER_TOTALS;
        }

        $this->entriesMemo = null;
        $this->registerMemo = null;

        // The register table is configured from the applied ranges (one
        // column per display day, one row per in-scope employee), so it
        // must be rebuilt after the filters change — booted() only ran
        // before the action on this request.
        $this->resetTable();
    }

    public function resetFilters(): void
    {
        $this->filterForm->fill([
            'employee_id' => null,
            'view_mode'   => 'month',
            'month'       => Carbon::today()->month,
            'year'        => Carbon::today()->year,
            'date_from'   => Carbon::today()->startOfMonth()->toDateString(),
            'date_to'     => Carbon::today()->toDateString(),
        ]);

        $this->applyFilters();
    }

    public function showRegisterView(string $view): void
    {
        if (! in_array($view, [self::REGISTER_DAYS, self::REGISTER_TOTALS], true)) {
            return;
        }

        if ($view === self::REGISTER_DAYS && $this->rangeMode === 'year') {
            return;
        }

        $this->registerView = $view;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return AttendanceRegisterTable::configure($table, $this);
    }

    public function updatedTableRecordsPerPage(): void
    {
        if (! in_array((int) $this->tableRecordsPerPage, self::PER_PAGE_OPTIONS, true)) {
            $this->tableRecordsPerPage = 25;
        }

        session()->put([
            $this->getTablePerPageSessionKey() => $this->getTableRecordsPerPage(),
        ]);

        $this->resetPage();
    }

    /**
     * Reads the applied display range defensively. The `#[Locked]` props
     * block honest clients, this guard covers tampered snapshots or any
     * out-of-band write: an invalid or future-starting range falls back to
     * the current month instead of trusting bad input (form validation
     * only runs when the Apply action is pressed). A healed display range
     * always resets the data range to its first day..today, preserving
     * the displayFrom == dateFrom and dateTo <= today invariant.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDisplayRange(): array
    {
        $today = Carbon::today();

        if ($this->rangeMode === 'year') {
            if (! isset($this->displayFrom, $this->displayTo) || ! $this->yearRangeIsValid($this->displayFrom, $this->displayTo)) {
                $year = Carbon::hasFormat($this->displayFrom ?? '', 'Y-m-d')
                    ? min(max((int) substr((string) $this->displayFrom, 0, 4), $today->year - 2), $today->year)
                    : $today->year;

                $this->displayFrom = sprintf('%04d-01-01', $year);
                $this->displayTo = sprintf('%04d-12-31', $year);
                $this->dateFrom = $this->displayFrom;
                $this->dateTo = (string) min($this->displayTo, $today->toDateString());
                $this->entriesMemo = null;
                $this->registerMemo = null;
            }

            return [Carbon::parse($this->displayFrom), Carbon::parse($this->displayTo)];
        }

        if (
            ! isset($this->displayFrom, $this->displayTo)
            || ! $this->rangeIsValid($this->displayFrom, $this->displayTo)
            || $this->displayFrom > $today->toDateString()
        ) {
            $this->displayFrom = $today->copy()->startOfMonth()->toDateString();
            $this->displayTo = $today->copy()->endOfMonth()->toDateString();
            $this->dateFrom = $this->displayFrom;
            $this->dateTo = $today->toDateString();
            $this->entriesMemo = null;
            $this->registerMemo = null;
        }

        return [Carbon::parse($this->displayFrom), Carbon::parse($this->displayTo)];
    }

    /**
     * Reads the applied data range defensively: it must be a valid span
     * inside the display range and must not reach beyond today, otherwise
     * it falls back to displayFrom..min(displayTo, today).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveRange(): array
    {
        [$displayFrom, $displayTo] = $this->resolveDisplayRange();

        $isValid = isset($this->dateFrom, $this->dateTo)
            && Carbon::hasFormat($this->dateFrom, 'Y-m-d')
            && Carbon::hasFormat($this->dateTo, 'Y-m-d')
            && $this->dateFrom <= $this->dateTo
            && $this->dateFrom >= $displayFrom->toDateString()
            && $this->dateTo <= $displayTo->toDateString()
            && $this->dateTo <= Carbon::today()->toDateString()
            && ($this->rangeMode === 'year' || AttendanceOverviewService::rangeDays($this->dateFrom, $this->dateTo) <= AttendanceOverviewService::MAX_RANGE_DAYS);

        if (! $isValid) {
            $this->dateFrom = $displayFrom->toDateString();
            $this->dateTo = (string) min($displayTo->toDateString(), Carbon::today()->toDateString());
            $this->entriesMemo = null;
        }

        return [Carbon::parse($this->dateFrom), Carbon::parse($this->dateTo)];
    }

    protected function rangeIsValid(string $from, string $to): bool
    {
        if (! Carbon::hasFormat($from, 'Y-m-d') || ! Carbon::hasFormat($to, 'Y-m-d')) {
            return false;
        }

        if ($from > $to) {
            return false;
        }

        return AttendanceOverviewService::rangeDays($from, $to) <= AttendanceOverviewService::MAX_RANGE_DAYS;
    }

    /**
     * Year display ranges are always a whole January-to-December span of a
     * recent year (never a future one). The 93-day register guard does not
     * apply: year mode renders one column per month by construction.
     */
    protected function yearRangeIsValid(string $from, string $to): bool
    {
        if (! Carbon::hasFormat($from, 'Y-m-d') || ! Carbon::hasFormat($to, 'Y-m-d') || $from > $to) {
            return false;
        }

        $year = (int) substr($from, 0, 4);

        return $from === sprintf('%04d-01-01', $year)
            && $to === sprintf('%04d-12-31', $year)
            && $year <= Carbon::today()->year;
    }

    /**
     * @return list<array{employee_id: int, name: string, date: string, status: string, leave_label: ?string, half_day: bool, check_in: ?string, check_out: ?string, minutes: ?int}>
     */
    public function getEntries(): array
    {
        [$from, $to] = $this->resolveRange();

        return $this->entriesMemo ??= app(AttendanceOverviewService::class)->daily(
            $this->filteredEmployees(),
            $from,
            $to,
        )->all();
    }

    /**
     * Every calendar day of the display range as a table column — weekends
     * and future days included.
     *
     * @return list<string>
     */
    public function getGridDays(): array
    {
        [$from, $to] = $this->resolveDisplayRange();

        $days = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Register rows: employees with at least one rostered day in the
     * display range, each with one display status word per calendar day
     * plus summarized totals over the data part of the range.
     *
     * @return list<array{employee_id: int, name: string, calendar: ?string, cells: array<string, string>, totals: array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}}>
     */
    public function getGrid(): array
    {
        if ($this->registerMemo !== null) {
            return $this->registerMemo;
        }

        [$from, $to] = $this->resolveDisplayRange();

        if ($this->rangeMode === 'year') {
            return $this->registerMemo = app(AttendanceOverviewService::class)
                ->registerYearly($this->filteredEmployees(), (int) $from->format('Y'))
                ->all();
        }

        return $this->registerMemo = app(AttendanceOverviewService::class)
            ->register($this->filteredEmployees(), $from, $to)
            ->all();
    }

    /**
     * Summary tiles aggregated over every filtered employee (not just the
     * paginated page). The rate mirrors the per-row formula: absent ÷
     * expected workdays, with pending leave counting as absent (D4).
     *
     * @return list<array{label: string, value: int|float}>
     */
    public function getSummaryTotals(): array
    {
        $rows = collect($this->getGrid());

        $sum = fn (string $total): int => (int) $rows->sum(
            fn (array $row): int => $row['totals'][$total],
        );

        $workdays = $sum('workdays');
        $absent = $sum('absent');

        return [
            [
                'key'   => 'employees',
                'label' => __('attendance::filament/pages/overview.grid.totals.employees'),
                'value' => $rows->count(),
                'color' => 'gray',
                'icon'  => 'heroicon-m-user-group',
            ],
            [
                'key'   => 'present',
                'label' => __('attendance::filament/pages/overview.grid.totals.present'),
                'value' => $sum('present'),
                'color' => 'success',
                'icon'  => 'heroicon-m-check-circle',
            ],
            [
                'key'   => 'absent',
                'label' => __('attendance::filament/pages/overview.grid.totals.absent'),
                'value' => $absent,
                'color' => 'danger',
                'icon'  => 'heroicon-m-x-circle',
            ],
            [
                'key'   => 'on_leave',
                'label' => __('attendance::filament/pages/overview.grid.totals.on-leave'),
                'value' => $sum('on_leave'),
                'color' => 'info',
                'icon'  => 'heroicon-m-calendar-days',
            ],
            [
                'key'   => 'pending',
                'label' => __('attendance::filament/pages/overview.grid.totals.pending'),
                'value' => $sum('pending'),
                'color' => 'warning',
                'icon'  => 'heroicon-m-clock',
            ],
            [
                'key'   => 'holidays',
                'label' => __('attendance::filament/pages/overview.grid.totals.holidays'),
                'value' => $sum('holidays'),
                'color' => 'primary',
                'icon'  => 'heroicon-m-sparkles',
            ],
            [
                'key'   => 'rate',
                'label' => __('attendance::filament/pages/overview.grid.totals.rate'),
                'value' => $workdays === 0 ? 0.0 : round($absent / $workdays * 100, 1),
                'color' => 'danger',
                'icon'  => 'heroicon-m-chart-pie',
            ],
        ];
    }

    /**
     * Daily present/absent/leave buckets across the display range for the
     * trend chart. Pending leave lands in the absent bucket to mirror the
     * D4 absence figure; holidays, weekly off days and future days carry
     * no bars.
     *
     * @return list<array{date: string, label: string, present: int, absent: int, leave: int}>
     */
    public function getDailyTrend(): array
    {
        $grid = $this->getGrid();

        return collect($this->getGridDays())
            ->map(function (string $date) use ($grid): array {
                $present = 0;
                $absent = 0;
                $leave = 0;

                foreach ($grid as $row) {
                    match ($row['cells'][$date] ?? null) {
                        AttendanceOverviewService::STATUS_PRESENT => $present++,
                        AttendanceOverviewService::STATUS_ABSENT,
                        AttendanceOverviewService::STATUS_PENDING => $absent++,
                        AttendanceOverviewService::STATUS_ON_LEAVE,
                        AttendanceOverviewService::STATUS_HALF_DAY => $leave++,
                        default                                    => null,
                    };
                }

                return [
                    'date'    => $date,
                    'label'   => Carbon::parse($date)->translatedFormat('D j'),
                    'present' => $present,
                    'absent'  => $absent,
                    'leave'   => $leave,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Status legend badges: label and color for every register status.
     *
     * @return list<array{key: string, label: string, color: string}>
     */
    public function getLegend(): array
    {
        return collect([
            AttendanceOverviewService::STATUS_PRESENT,
            AttendanceOverviewService::STATUS_ABSENT,
            AttendanceOverviewService::STATUS_ON_LEAVE,
            AttendanceOverviewService::STATUS_HALF_DAY,
            AttendanceOverviewService::STATUS_PENDING,
            AttendanceOverviewService::STATUS_HOLIDAY,
            AttendanceOverviewService::STATUS_WEEKLY_OFF,
            AttendanceOverviewService::STATUS_FUTURE,
        ])
            ->map(fn (string $status): array => [
                'key'   => $status,
                'label' => AttendanceRegisterTable::statusLabel($status),
                'color' => AttendanceRegisterTable::statusColor($status) ?? 'gray',
            ])
            ->values()
            ->all();
    }

    /**
     * Both exports stay registered so the action cache (built once per
     * boot, before any toggle call runs) never goes stale; each action
     * only shows itself for its own register view, evaluated per render.
     */
    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('attendance::filament/pages/overview.export'))
                ->icon('heroicon-o-arrow-up-tray')
                ->exporter(AbsenceReportExporter::class)
                ->maxRows(10000)
                ->visible(fn (): bool => $this->registerView === self::REGISTER_TOTALS)
                ->modifyQueryUsing(fn (Builder $query, array $options): Builder => AbsenceReportExporter::scopeQuery($query, $options)),
            ExportAction::make('exportDetail')
                ->label(__('attendance::filament/pages/overview.export-detail'))
                ->icon('heroicon-o-arrow-down-tray')
                ->exporter(AbsenceDetailExporter::class)
                ->job(PrepareAbsenceDetailExport::class)
                ->maxRows(fn (): int => AbsenceDetailExporter::maxRows())
                ->visible(fn (): bool => $this->registerView !== self::REGISTER_TOTALS)
                ->modifyQueryUsing(fn (Builder $query, array $options): Builder => AbsenceReportExporter::scopeQuery($query, $options)),
        ];
    }

    protected function filteredEmployees(): Collection
    {
        return Employee::query()
            ->when($this->employeeId, fn (Builder $query) => $query->whereKey($this->employeeId))
            ->orderBy('name')
            ->get();
    }
}
