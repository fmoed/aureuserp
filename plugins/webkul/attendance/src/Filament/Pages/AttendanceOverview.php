<?php

namespace Webkul\Attendance\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Webkul\Attendance\Filament\Actions\RebuildAttendanceAction;
use Webkul\Attendance\Filament\Exports\AbsenceDetailExporter;
use Webkul\Attendance\Filament\Exports\AbsenceReportExporter;
use Webkul\Attendance\Filament\Exports\Jobs\PrepareAbsenceDetailExport;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource\Tables\AttendanceEventsTable;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Tables\AttendancesTable;
use Webkul\Attendance\Filament\Tables\AttendanceRegisterTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Policies\AttendanceEventPolicy;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Services\ShiftWindowResolver;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Infolists\Components\RepeatableEntry;
use Webkul\Support\Models\Calendar;

/**
 * Attendance register: one row per employee, one column
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

    #[Locked]
    public ?int $departmentId = null;

    #[Locked]
    public ?int $calendarId = null;

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

    /**
     * @return array{employee_id: null, department_id: null, calendar_id: null, view_mode: string, month: int, year: int, date_from: string, date_to: string}
     */
    protected function defaultFilterValues(): array
    {
        return [
            'employee_id'   => null,
            'department_id' => null,
            'calendar_id'   => null,
            'view_mode'     => 'month',
            'month'         => Carbon::today()->month,
            'year'          => Carbon::today()->year,
            'date_from'     => Carbon::today()->startOfMonth()->toDateString(),
            'date_to'       => Carbon::today()->toDateString(),
        ];
    }

    public function mount(): void
    {
        // The tab arrives via the URL (#[Url] restores it before mount),
        // so a tampered query value must fall back instead of being trusted.
        if (! in_array($this->registerView, [self::REGISTER_DAYS, self::REGISTER_TOTALS], true)) {
            $this->registerView = self::REGISTER_DAYS;
        }

        $defaults = $this->defaultFilterValues();

        $this->filterData = $defaults;
        $this->tableFilters = ['overview' => $defaults];
        $this->tableDeferredFilters = ['overview' => $defaults];

        $this->filterForm->fill($defaults);

        $this->applyFilterState($defaults, rebuildTable: false);
    }

    protected function getForms(): array
    {
        return [
            'filterForm',
        ];
    }

    /**
     * Filter components shared by the table toolbar filter popover and
     * the page's filter schema.
     *
     * @return list<Component>
     */
    public function getFilterFormComponents(): array
    {
        $isMonth = fn (Get $get): bool => ($get('view_mode') ?? 'month') === 'month';
        $isRange = fn (Get $get): bool => $get('view_mode') === 'range';
        $isMonthOrYear = fn (Get $get): bool => in_array($get('view_mode') ?? 'month', ['month', 'year'], true);

        // Year mode is summary-only (it forces the totals tab), so its
        // option only makes sense while the totals tab is active.
        $modeOptions = [
            'month' => __('attendance::filament/pages/overview.form.mode-month'),
            'range' => __('attendance::filament/pages/overview.form.mode-range'),
            'year'  => __('attendance::filament/pages/overview.form.mode-year'),
        ];

        return [
            Select::make('employee_id')
                ->label(__('attendance::filament/pages/overview.form.employee'))
                ->options(fn (): array => AttendanceOverviewService::searchEligibleEmployees())
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => AttendanceOverviewService::searchEligibleEmployees($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => AttendanceOverviewService::employeeOptionLabel($value))
                ->placeholder(__('attendance::filament/pages/overview.form.all-employees'))
                ->columnSpanFull(),
            Select::make('department_id')
                ->label(__('attendance::filament/pages/overview.form.department'))
                ->options(fn (): array => AttendanceOverviewService::departmentOptions())
                ->searchable()
                ->placeholder(__('attendance::filament/pages/overview.form.all-departments')),
            Select::make('calendar_id')
                ->label(__('attendance::filament/pages/overview.form.calendar'))
                ->options(fn (): array => Calendar::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->placeholder(__('attendance::filament/pages/overview.form.all-calendars')),
            Select::make('view_mode')
                ->label(__('attendance::filament/pages/overview.form.view-mode'))
                ->options($modeOptions)
                ->default('month')
                ->required()
                ->selectablePlaceholder(false)
                ->live()
                ->columnSpanFull(),
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
                ->default(fn (): int => Carbon::today()->month)
                ->required()
                ->selectablePlaceholder(false)
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
                ->default(fn (): int => Carbon::today()->year)
                ->required()
                ->selectablePlaceholder(false)
                ->live()
                ->visible($isMonthOrYear)
                ->columnSpan(fn (Get $get): int => ($get('view_mode') ?? 'month') === 'year' ? 2 : 1)
                ->helperText(__('attendance::filament/pages/overview.form.year-hint')),
            DatePicker::make('date_from')
                ->label(__('attendance::filament/pages/overview.form.date-from'))
                ->default(fn (): string => Carbon::today()->startOfMonth()->toDateString())
                ->required()
                ->maxDate(Carbon::today()->toDateString())
                ->visible($isRange)
                ->helperText(__('attendance::filament/pages/overview.form.range-hint', [
                    'days' => AttendanceOverviewService::MAX_RANGE_DAYS,
                ])),
            DatePicker::make('date_to')
                ->label(__('attendance::filament/pages/overview.form.date-to'))
                ->default(fn (): string => Carbon::today()->toDateString())
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
        ];
    }

    public function filterForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filterData')
            ->components($this->getFilterFormComponents())
            ->columns(4);
    }

    /**
     * Active filter indicators rendered natively inside the table header.
     *
     * @return array<int|string, Indicator>
     */
    public function getTableFilterIndicators(): array
    {
        $indicators = [
            Indicator::make(__('attendance::filament/pages/overview.filter-chips.period').': '.$this->appliedPeriodLabel())
                ->removable(false),
        ];

        if ($appliedEmployee = $this->appliedEmployeeName()) {
            $indicators['employee_id'] = Indicator::make(__('attendance::filament/pages/overview.filter-chips.employee').': '.$appliedEmployee)
                ->removeField('employee_id');
        }

        if ($appliedDepartment = $this->appliedDepartmentName()) {
            $indicators['department_id'] = Indicator::make(__('attendance::filament/pages/overview.filter-chips.department').': '.$appliedDepartment)
                ->removeField('department_id');
        }

        if ($appliedCalendar = $this->appliedCalendarName()) {
            $indicators['calendar_id'] = Indicator::make(__('attendance::filament/pages/overview.filter-chips.calendar').': '.$appliedCalendar)
                ->removeField('calendar_id');
        }

        return $indicators;
    }

    /**
     * Native table filter Apply handler: validates the table filter popover
     * form, syncs the filter state, and rebuilds the register columns.
     */
    public function applyTableFilters(): void
    {
        $validated = $this->getTableFiltersForm()->getState();

        $raw = $this->tableDeferredFilters['overview'] ?? [];
        $data = array_merge($this->defaultFilterValues(), $raw, $validated['overview'] ?? []);

        $this->filterData = $data;
        $this->tableFilters = ['overview' => $data];
        $this->tableDeferredFilters = ['overview' => $data];

        $this->applyFilterState($data, rebuildTable: true);

        $this->handleTableFilterUpdates();
    }

    public function applyFilters(): void
    {
        $validated = $this->filterForm->getState();

        $data = array_merge($this->defaultFilterValues(), $this->filterData ?? [], $validated);

        $this->filterData = $data;
        $this->tableFilters = ['overview' => $data];
        $this->tableDeferredFilters = ['overview' => $data];

        $this->applyFilterState($data, rebuildTable: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function applyFilterState(array $data, bool $rebuildTable = true): void
    {
        $this->employeeId = ! empty($data['employee_id']) ? (int) $data['employee_id'] : null;
        $this->departmentId = ! empty($data['department_id']) ? (int) $data['department_id'] : null;
        $this->calendarId = ! empty($data['calendar_id']) ? (int) $data['calendar_id'] : null;

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

        if ($rebuildTable) {
            $this->resetTable();
        }
    }

    public function resetTableFiltersForm(): void
    {
        $this->resetFilters();
    }

    public function resetFilters(): void
    {
        $defaults = $this->defaultFilterValues();

        $this->filterData = $defaults;
        $this->tableFilters = ['overview' => $defaults];
        $this->tableDeferredFilters = ['overview' => $defaults];

        $this->filterForm->fill($defaults);

        $this->applyFilterState($defaults, rebuildTable: true);
    }

    public function removeTableFilter(string $filterName, ?string $field = null, bool $isRemovingAllFilters = false): void
    {
        if ($field === 'employee_id') {
            $this->clearEmployee();

            return;
        }

        if ($field === 'department_id') {
            $this->clearDepartment();

            return;
        }

        if ($field === 'calendar_id') {
            $this->clearCalendar();

            return;
        }

        $this->resetFilters();
    }

    public function removeTableFilters(): void
    {
        $this->resetFilters();
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
    }

    public function showRegisterView(string $view): void
    {
        if (! in_array($view, [self::REGISTER_DAYS, self::REGISTER_TOTALS], true)) {
            return;
        }

        $this->registerView = $view;

        // If switching from year-summary mode back to the daily register,
        // automatically switch the time range mode back to the current month
        // so the daily columns can be displayed immediately.
        if ($view === self::REGISTER_DAYS && $this->rangeMode === 'year') {
            $today = Carbon::today();
            $this->filterData['view_mode'] = 'month';
            $this->filterData['month'] = $today->month;
            $this->filterData['year'] = $today->year;

            if (isset($this->tableFilters['overview'])) {
                $this->tableFilters['overview']['view_mode'] = 'month';
                $this->tableFilters['overview']['month'] = $today->month;
                $this->tableFilters['overview']['year'] = $today->year;
            }

            if (isset($this->tableDeferredFilters['overview'])) {
                $this->tableDeferredFilters['overview']['view_mode'] = 'month';
                $this->tableDeferredFilters['overview']['month'] = $today->month;
                $this->tableDeferredFilters['overview']['year'] = $today->year;
            }

            $this->applyFilterState($this->filterData, rebuildTable: true);

            return;
        }

        $this->resetTable();
    }

    /**
     * Rebuilds the dynamic register table (columns + filter schema + column
     * manager state) without wiping the applied filter values.
     */
    public function resetTable(): void
    {
        $this->cachedDefaultTableColumnState = null;
        $this->tableColumns = [];
        $this->tableColumnsToggleStateByName = null;
        $this->hasReorderableTableColumns = null;

        $this->table = $this->table($this->makeTable());

        $this->cacheSchema('tableFiltersForm', $this->getTableFiltersForm(...));
        $this->getTableFiltersForm()->fill($this->tableDeferredFilters ?? $this->tableFilters ?? ['overview' => $this->filterData]);

        $this->initTableColumnManager();

        $this->resetPage();

        $this->flushCachedTableRecords();
    }

    /**
     * Applied period label for the filter chips, localized on the
     * healed display range (month name, explicit range or bare year).
     */
    public function appliedPeriodLabel(): string
    {
        [$from, $to] = $this->resolveDisplayRange();

        if ($this->rangeMode === 'year') {
            return $from->format('Y');
        }

        if ($this->rangeMode === 'month') {
            return $from->locale(app()->getLocale())->translatedFormat('F Y');
        }

        return $from->locale(app()->getLocale())->translatedFormat('d M Y')
            .' → '
            .$to->locale(app()->getLocale())->translatedFormat('d M Y');
    }

    /**
     * Applied employee name for the filter chips (null = all employees).
     */
    public function appliedEmployeeName(): ?string
    {
        if (! $this->employeeId) {
            return null;
        }

        return AttendanceOverviewService::employeeOptionLabel($this->employeeId);
    }

    public function appliedDepartmentName(): ?string
    {
        if (! $this->departmentId) {
            return null;
        }

        return Department::query()->whereKey($this->departmentId)->value('name');
    }

    public function appliedCalendarName(): ?string
    {
        if (! $this->calendarId) {
            return null;
        }

        return Calendar::query()->whereKey($this->calendarId)->value('name');
    }

    /**
     * One-click chip action: drops the employee filter and re-applies the
     * remaining filters.
     */
    public function clearEmployee(): void
    {
        $this->clearFilterField('employee_id');
    }

    public function clearDepartment(): void
    {
        $this->clearFilterField('department_id');
    }

    public function clearCalendar(): void
    {
        $this->clearFilterField('calendar_id');
    }

    protected function clearFilterField(string $field): void
    {
        $this->filterData[$field] = null;

        if (isset($this->tableFilters['overview'])) {
            $this->tableFilters['overview'][$field] = null;
        }

        if (isset($this->tableDeferredFilters['overview'])) {
            $this->tableDeferredFilters['overview'][$field] = null;
        }

        $this->filterForm->fill($this->filterData);

        $this->applyFilterState($this->filterData, rebuildTable: true);
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

        $sum = function (string $total) use ($rows): int|float {
            $value = $rows->sum(fn (array $row): float => (float) $row['totals'][$total]);

            return abs($value - round($value)) < 0.0001 ? (int) round($value) : round($value, 1);
        };

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
     * Aggregate hours across the filtered employees for the KPI strip:
     * worked versus scheduled minutes, overtime, breaks, late minutes and
     * missing check-outs. Weighted totals are summed as floats so half
     * days stay visible.
     *
     * @return array{scheduled: int, worked: int, overtime: int, break: int, late: int, missing_checkouts: int}
     */
    public function getHoursSummary(): array
    {
        $rows = collect($this->getGrid());

        $sum = fn (string $field): int => (int) round($rows->sum(
            fn (array $row): float => (float) ($row['totals'][$field] ?? 0),
        ));

        return [
            'scheduled'         => $sum('scheduled_minutes'),
            'worked'            => $sum('minutes'),
            'overtime'          => $sum('overtime_minutes'),
            'break'             => $sum('break_minutes'),
            'late'              => $sum('late_minutes'),
            'missing_checkouts' => $sum('missing_checkouts'),
        ];
    }

    /**
     * Daily present/absent/leave buckets across the display range for the
     * trend chart. Pending leave lands in the absent bucket to mirror the
     * D4 absence figure; weekend and holiday work count as present, a
     * missing check-out as absent; holidays, weekly off days and future
     * days carry no bars.
     *
     * @return list<array{date: string, label: string, present: int, absent: int, leave: int}>
     */
    public function getDailyTrend(): array
    {
        $grid = $this->getGrid();
        $pendingCountsAsAbsent = AttendanceSettings::countPendingLeaveAsAbsent();

        return collect($this->getGridDays())
            ->map(function (string $date) use ($grid, $pendingCountsAsAbsent): array {
                $present = 0;
                $absent = 0;
                $leave = 0;
                $off = 0;

                foreach ($grid as $row) {
                    match ($row['cells'][$date] ?? null) {
                        AttendanceOverviewService::STATUS_PRESENT,
                        AttendanceOverviewService::STATUS_WEEKEND_PRESENT,
                        AttendanceOverviewService::STATUS_HOLIDAY_PRESENT => $present++,
                        AttendanceOverviewService::STATUS_ABSENT,
                        AttendanceOverviewService::STATUS_MISSING_CHECKOUT => $absent++,
                        AttendanceOverviewService::STATUS_PENDING          => $pendingCountsAsAbsent ? $absent++ : $leave++,
                        AttendanceOverviewService::STATUS_ON_LEAVE,
                        AttendanceOverviewService::STATUS_HALF_DAY => $leave++,
                        AttendanceOverviewService::STATUS_WEEKLY_OFF,
                        AttendanceOverviewService::STATUS_HOLIDAY  => $off++,
                        default                                    => null,
                    };
                }

                $total = $present + $absent + $leave + $off;

                return [
                    'date'    => $date,
                    'label'   => Carbon::parse($date)->translatedFormat('D j'),
                    'present' => $present,
                    'absent'  => $absent,
                    'leave'   => $leave,
                    'off'     => $off,
                    'total'   => $total,
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
            AttendanceOverviewService::STATUS_MISSING_CHECKOUT,
            AttendanceOverviewService::STATUS_HOLIDAY,
            AttendanceOverviewService::STATUS_WEEKEND_PRESENT,
            AttendanceOverviewService::STATUS_HOLIDAY_PRESENT,
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
            AbsenceReportExporter::configureExportAction(
                ExportAction::make()
                    ->label(__('attendance::filament/pages/overview.export'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->exporter(AbsenceReportExporter::class)
                    ->maxRows(10000)
                    ->authorize(fn (): bool => static::canAccess())
                    ->visible(fn (): bool => $this->registerView === self::REGISTER_TOTALS)
                    ->modifyQueryUsing(fn (Builder $query, array $options): Builder => AbsenceReportExporter::scopeQuery($query, $options)),
            ),
            AbsenceDetailExporter::configureExportAction(
                ExportAction::make('exportDetail')
                    ->label(__('attendance::filament/pages/overview.export-detail'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(AbsenceDetailExporter::class)
                    ->job(PrepareAbsenceDetailExport::class)
                    ->columnMapping(false)
                    ->authorize(fn (): bool => static::canAccess())
                    ->visible(fn (): bool => $this->registerView !== self::REGISTER_TOTALS)
                    ->modifyQueryUsing(fn (Builder $query, array $options): Builder => AbsenceReportExporter::scopeQuery($query, $options)),
            ),
            RebuildAttendanceAction::make(),
        ];
    }

    public function inspectDayCellAction(): Action
    {
        return Action::make('inspectDayCell')
            ->label(__('attendance::filament/pages/overview.inspect.action_label'))
            ->slideOver()
            ->modalWidth(Width::FourExtraLarge)
            ->modalHeading(function (array $arguments): string {
                $employee = Employee::query()->find($arguments['employee_id'] ?? null);
                $date = $arguments['date'] ?? null;
                $formattedDate = $date ? Carbon::parse($date)->translatedFormat('l, j F Y') : '';

                return $employee ? "{$employee->name} — {$formattedDate}" : __('attendance::filament/pages/overview.inspect.heading');
            })
            ->modalDescription(__('attendance::filament/pages/overview.inspect.description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('attendance::filament/pages/overview.inspect.close'))
            ->authorize(function (array $arguments): bool {
                $employeeId = (int) ($arguments['employee_id'] ?? 0);
                if ($employeeId <= 0) {
                    return false;
                }

                return AttendancePolicy::scopeEmployeeQuery(Employee::query())->whereKey($employeeId)->exists();
            })
            ->schema(fn (array $arguments): array => $this->buildInspectDaySchema($arguments))
            ->extraModalFooterActions(fn (Action $action): array => $this->buildInspectDayFooterActions($action));
    }

    protected function buildInspectDaySchema(array $arguments): array
    {
        $employeeId = (int) ($arguments['employee_id'] ?? 0);
        $date = (string) ($arguments['date'] ?? '');

        if ($employeeId <= 0 || blank($date)) {
            return [];
        }

        $employee = Employee::with('calendar')->find($employeeId);
        if (! $employee) {
            return [];
        }

        $service = app(AttendanceOverviewService::class);
        $calendar = $service->calendarFor($employee);
        $dayObj = Carbon::parse($date);
        $registerRow = $service->register(collect([$employee]), $dayObj, $dayObj)->first();
        $cellStatus = (string) ($registerRow['cells'][$date] ?? AttendanceOverviewService::STATUS_WEEKLY_OFF);
        $meta = (array) ($registerRow['meta'][$date] ?? []);

        $tz = $employee->time_zone ?: config('app.timezone', 'UTC');
        $scheduledMinutes = isset($meta['scheduled_minutes'])
            ? (int) $meta['scheduled_minutes']
            : ($calendar ? app(ShiftWindowResolver::class)->scheduledMinutesForDay($calendar, $date, $tz) : 0);
        $workedMinutes = (int) ($meta['minutes'] ?? 0);
        $workedHours = AttendanceRegisterTable::formatMinutesToHours($workedMinutes);
        $scheduledHours = AttendanceRegisterTable::formatMinutesToHours($scheduledMinutes);

        $dayStartUtc = Carbon::parse($date.' 00:00:00', $tz)->utc()->format('Y-m-d H:i:s');
        $dayEndUtc = Carbon::parse($date.' 23:59:59', $tz)->utc()->format('Y-m-d H:i:s');

        $attendances = AttendancePolicy::scopeQuery(Attendance::query())
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->orderBy('check_in')
            ->get();

        $attendanceIds = $attendances->modelKeys();

        $events = AttendanceEventPolicy::scopeQuery(AttendanceEvent::query())
            ->where('employee_id', $employeeId)
            ->where(function (Builder $query) use ($dayStartUtc, $dayEndUtc, $attendanceIds): void {
                $query->whereBetween('punched_at', [$dayStartUtc, $dayEndUtc]);

                if ($attendanceIds !== []) {
                    $query->orWhereIn('attendance_id', $attendanceIds);
                }
            })
            ->orderBy('punched_at')
            ->get();

        $firstRec = $attendances->first();
        $lastRec = $attendances->last();
        $firstIn = $firstRec
            ? (AttendancesTable::employeeWallClock($firstRec, 'check_in')?->format('H:i') ?? '—')
            : '—';
        $lastOut = $lastRec
            ? ($lastRec->check_out !== null
                ? (AttendancesTable::employeeWallClock($lastRec, 'check_out')?->format('H:i') ?? '—')
                    .(AttendancesTable::crossesMidnight($lastRec) ? ' (+1d)' : '')
                : __('attendance::filament/pages/overview.inspect.missing_checkout'))
            : '—';

        return [
            Section::make(__('attendance::filament/pages/overview.inspect.evaluation_title'))
                ->schema([
                    Grid::make(4)
                        ->schema([
                            TextEntry::make('status')
                                ->label(__('attendance::filament/pages/overview.inspect.status'))
                                ->state(AttendanceRegisterTable::statusLabel($cellStatus))
                                ->badge()
                                ->color(AttendanceRegisterTable::statusColor($cellStatus)),
                            TextEntry::make('shift')
                                ->label(__('attendance::filament/pages/overview.inspect.shift'))
                                ->state($calendar?->name ?? __('attendance::filament/pages/overview.inspect.no_shift')),
                            TextEntry::make('worked_hours')
                                ->label(__('attendance::filament/pages/overview.inspect.worked_hours'))
                                ->state("{$workedHours} / {$scheduledHours}"),
                            TextEntry::make('times')
                                ->label(__('attendance::filament/pages/overview.inspect.first_in_last_out'))
                                ->state("{$firstIn} → {$lastOut}"),
                        ]),
                    Grid::make(4)
                        ->schema([
                            TextEntry::make('late')
                                ->label(__('attendance::filament/pages/overview.inspect.late_minutes'))
                                ->state(((int) ($meta['late_minutes'] ?? 0)).' min'),
                            TextEntry::make('early_leave')
                                ->label(__('attendance::filament/pages/overview.inspect.early_leave_minutes'))
                                ->state(((int) ($meta['early_leave_minutes'] ?? 0)).' min'),
                            TextEntry::make('overtime')
                                ->label(__('attendance::filament/pages/overview.inspect.overtime_minutes'))
                                ->state(((int) ($meta['overtime_minutes'] ?? 0)).' min'),
                            TextEntry::make('break')
                                ->label(__('attendance::filament/pages/overview.inspect.break_minutes'))
                                ->state(((int) ($meta['break_minutes'] ?? 0)).' min'),
                        ]),
                ]),

            Section::make(__('attendance::filament/pages/overview.inspect.sessions_title'))
                ->schema([
                    TextEntry::make('sessions_empty')
                        ->hiddenLabel()
                        ->state(__('attendance::filament/pages/overview.inspect.no_sessions'))
                        ->color('gray')
                        ->visible($attendances->isEmpty()),

                    RepeatableEntry::make('attendances')
                        ->hiddenLabel()
                        ->state($attendances)
                        ->visible($attendances->isNotEmpty())
                        ->schema([
                            Grid::make(4)
                                ->schema([
                                    TextEntry::make('check_in')
                                        ->label(__('attendance::filament/resources/attendance.form.check-in'))
                                        ->state(fn (Attendance $rec): string => AttendancesTable::employeeWallClock($rec, 'check_in')?->format('Y-m-d H:i:s') ?? '—'),
                                    TextEntry::make('check_out')
                                        ->label(__('attendance::filament/resources/attendance.form.check-out'))
                                        ->state(fn (Attendance $rec): string => $rec->check_out !== null
                                            ? (AttendancesTable::employeeWallClock($rec, 'check_out')?->format('Y-m-d H:i:s') ?? '—')
                                                .(AttendancesTable::crossesMidnight($rec) ? ' (+1d)' : '')
                                            : __('attendance::filament/pages/overview.inspect.missing_checkout'))
                                        ->color(fn (Attendance $rec): ?string => $rec->check_out !== null ? null : 'danger'),
                                    TextEntry::make('worked_minutes')
                                        ->label(__('attendance::filament/pages/overview.inspect.duration'))
                                        ->state(fn (Attendance $rec): string => $rec->worked_minutes !== null ? AttendanceRegisterTable::formatMinutesToHours((int) $rec->worked_minutes) : '—'),
                                    TextEntry::make('source')
                                        ->label(__('attendance::filament/resources/attendance.form.source'))
                                        ->state(fn (Attendance $rec): string => Attendance::sourceDisplayName((string) $rec->source, $rec->source_label))
                                        ->badge(),
                                ]),
                        ]),
                ]),

            Section::make(__('attendance::filament/pages/overview.inspect.raw_punches_title'))
                ->schema([
                    TextEntry::make('punches_empty')
                        ->hiddenLabel()
                        ->state(__('attendance::filament/pages/overview.inspect.no_punches'))
                        ->color('gray')
                        ->visible($events->isEmpty()),

                    RepeatableEntry::make('raw_events')
                        ->hiddenLabel()
                        ->state($events)
                        ->visible($events->isNotEmpty())
                        ->schema([
                            Grid::make(4)
                                ->schema([
                                    TextEntry::make('punched_at')
                                        ->label(__('attendance::filament/resources/attendance-event.table.columns.punch-time'))
                                        ->state(fn (AttendanceEvent $ev): string => AttendanceEventsTable::eventWallClock($ev)?->format('H:i:s') ?? '—'),
                                    TextEntry::make('direction')
                                        ->label(__('attendance::filament/resources/attendance-event.table.columns.direction'))
                                        ->state(fn (AttendanceEvent $ev): string => AttendanceEvent::directionLabel((int) $ev->direction)),
                                    TextEntry::make('source')
                                        ->label(__('attendance::filament/resources/attendance-event.table.columns.source'))
                                        ->state(fn (AttendanceEvent $ev): string => Attendance::sourceDisplayName((string) $ev->source, $ev->source_label)),
                                    TextEntry::make('status')
                                        ->label(__('attendance::filament/pages/overview.inspect.status'))
                                        ->badge()
                                        ->state(fn (AttendanceEvent $ev): string => $ev->attendance_id !== null
                                            ? __('attendance::filament/pages/overview.inspect.paired')
                                            : __('attendance::filament/pages/overview.inspect.unpaired'))
                                        ->color(fn (AttendanceEvent $ev): string => $ev->attendance_id !== null ? 'success' : 'warning'),
                                ]),
                        ]),
                ]),
        ];
    }

    protected function buildInspectDayFooterActions(Action $action): array
    {
        $arguments = $action->getArguments();
        $employeeId = (int) ($arguments['employee_id'] ?? 0);
        $date = (string) ($arguments['date'] ?? '');

        if ($employeeId <= 0 || blank($date) || AttendanceSettings::isLockedDate($date)) {
            return [];
        }

        $employee = Employee::query()->find($employeeId);
        if (! $employee) {
            return [];
        }

        $user = auth()->user();
        $openAttendance = Attendance::query()
            ->where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->whereNull('check_out')
            ->first();

        $actions = [];

        if ($openAttendance && $user?->can('update', $openAttendance)) {
            $actions[] = Action::make('completeDayCheckout')
                ->label(__('attendance::filament/pages/overview.inspect.actions.complete_checkout'))
                ->icon('heroicon-o-pencil-square')
                ->color('success')
                ->url(AttendanceResource::getUrl('edit', ['record' => $openAttendance]))
                ->openUrlInNewTab(false);
        }

        if ($user?->can('create', Attendance::class)) {
            $actions[] = Action::make('addManualAttendanceDay')
                ->label(__('attendance::filament/pages/overview.inspect.actions.add_manual'))
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(AttendanceResource::getUrl('create', [
                    'employee_id' => $employee->id,
                    'work_date'   => $date,
                ]))
                ->openUrlInNewTab(false);
        }

        return $actions;
    }

    protected function filteredEmployees(): Collection
    {
        return AttendancePolicy::scopeEmployeeQuery(
            AttendanceOverviewService::scopeEligibleEmployees(Employee::query()),
        )
            ->when($this->employeeId, fn (Builder $query) => $query->whereKey($this->employeeId))
            ->when($this->departmentId, fn (Builder $query) => AttendanceOverviewService::scopeByDepartment($query, $this->departmentId))
            ->when($this->calendarId, fn (Builder $query) => AttendanceOverviewService::scopeByCalendar($query, $this->calendarId))
            ->orderBy('name')
            ->get();
    }
}
