<?php

namespace Webkul\Attendance\Filament\Exports\Concerns;

use Closure;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Department;
use Webkul\Support\Models\Calendar;

trait HasAbsenceExportOptions
{
    /**
     * Download name: what + period + scope, so the file identifies itself
     * (the Filament default is export-{id}-{model}). Employee names stay
     * ASCII-safe: a blank slug (e.g. an Arabic-only name) falls back to
     * the employee id.
     */
    public function getFileName(Export $export): string
    {
        $options = $this->getOptions();
        $from = self::fileNameDate($options['date_from'] ?? null);
        $to = self::fileNameDate($options['date_to'] ?? null);

        $scope = 'all';

        if (filled($options['employee_id'] ?? null)) {
            $scope = 'emp-'.self::fileNameScope((array) $options['employee_id'], fn (int $id): string => self::fileNameEmployeeSlug($id));
        } elseif (filled($options['department_id'] ?? null)) {
            $scope = 'dept-'.self::fileNameScope((array) $options['department_id'], fn (int $id): string => self::fileNameNamedSlug(Department::class, $id));
        } elseif (filled($options['calendar_id'] ?? null)) {
            $scope = 'shift-'.self::fileNameScope((array) $options['calendar_id'], fn (int $id): string => self::fileNameNamedSlug(Calendar::class, $id));
        }

        return sprintf('%s_%s_to_%s_%s', static::exportFilePrefix(), $from, $to, $scope);
    }

    /**
     * A single selected id resolves to its name, several to their count.
     *
     * @param  array<int, mixed>  $ids
     */
    protected static function fileNameScope(array $ids, Closure $slug): string
    {
        $ids = collect($ids)->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();

        if ($ids->count() === 1) {
            return $slug((int) $ids->first());
        }

        return (string) $ids->count();
    }

    protected static function exportFilePrefix(): string
    {
        return 'absence-report';
    }

    protected static function fileNameDate(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return 'undated';
    }

    /**
     * Keep Arabic (and any other letter) in filenames: only strip
     * filesystem-reserved characters and collapse separators, instead of
     * ASCII slugging that erases non-Latin employee names entirely.
     */
    protected static function fileNameEmployeeSlug(int $employeeId): string
    {
        $name = trim((string) AttendanceOverviewService::eligibleEmployeeQuery()
            ->whereKey($employeeId)
            ->value('name'));

        return self::sanitizeFileNameSegment($name) ?: (string) $employeeId;
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected static function fileNameNamedSlug(string $model, int $id): string
    {
        $name = trim((string) $model::query()->whereKey($id)->value('name'));

        return self::sanitizeFileNameSegment($name) ?: (string) $id;
    }

    protected static function sanitizeFileNameSegment(string $text): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}\-_ ]+/u', '', trim($text)) ?? '';
        $slug = preg_replace('/[\s_]+/u', '-', $slug) ?? '';

        return mb_strtolower(trim(mb_substr(trim($slug, '-_'), 0, 80), '-_'));
    }

    /**
     * Widest range this exporter accepts. The detail export keeps the
     * 93-day register guard (one row per employee AND day); the summary
     * exporter overrides this to a full year (one row per employee).
     */
    protected static function exportRangeLimit(): int
    {
        return AttendanceOverviewService::MAX_RANGE_DAYS;
    }

    /**
     * Export scope shared by the summary and the detail export: one
     * employee (prefilled from the page filters) and an explicit date
     * range validated to this exporter's window.
     *
     * @return array<int, Component>
     */
    public static function getOptionsFormComponents(): array
    {
        $maxRangeDays = static::exportRangeLimit();

        return [
            Hidden::make('locale')
                ->default(fn (): string => app()->getLocale()),
            Select::make('employee_id')
                ->label(__('attendance::filament/exports/absence-report.options.employee'))
                ->multiple()
                ->options(fn (): array => AttendanceOverviewService::searchEligibleEmployees())
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => AttendanceOverviewService::searchEligibleEmployees($search))
                ->getOptionLabelsUsing(fn (array $values): array => AttendanceOverviewService::employeeOptionLabels($values))
                ->nullable()
                ->default(fn ($livewire): array => static::defaultFilterValues($livewire, 'employee_id')),
            Select::make('department_id')
                ->label(__('attendance::filament/exports/absence-report.options.department'))
                ->multiple()
                ->options(fn (): array => AttendanceOverviewService::departmentOptions())
                ->searchable()
                ->nullable()
                ->default(fn ($livewire): array => static::defaultFilterValues($livewire, 'department_id')),
            Select::make('calendar_id')
                ->label(__('attendance::filament/exports/absence-report.options.calendar'))
                ->multiple()
                ->options(fn (): array => Calendar::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->nullable()
                ->default(fn ($livewire): array => static::defaultFilterValues($livewire, 'calendar_id')),
            DatePicker::make('date_from')
                ->label(__('attendance::filament/exports/absence-report.options.date-from'))
                ->required()
                ->maxDate(Carbon::today()->toDateString())
                ->default(function ($livewire): string {
                    if ($livewire instanceof AttendanceOverview && filled($livewire->dateFrom)) {
                        return $livewire->dateFrom;
                    }

                    if (method_exists($livewire, 'getTableFilterState')) {
                        $workDate = $livewire->getTableFilterState('work_date');
                        if (! empty($workDate['preset'])) {
                            return match ($workDate['preset']) {
                                'today'     => Carbon::today()->toDateString(),
                                'this_week' => Carbon::now()->startOfWeek()->toDateString(),
                                default     => Carbon::now()->startOfMonth()->toDateString(),
                            };
                        }

                        if (! empty($workDate['work_date_from'])) {
                            return $workDate['work_date_from'];
                        }

                        $punchedAt = $livewire->getTableFilterState('punched_at');
                        if (! empty($punchedAt['punched_from'])) {
                            return $punchedAt['punched_from'];
                        }
                    }

                    return Carbon::today()->startOfMonth()->toDateString();
                }),
            DatePicker::make('date_to')
                ->label(__('attendance::filament/exports/absence-report.options.date-to'))
                ->required()
                ->afterOrEqual('date_from')
                ->maxDate(Carbon::today()->toDateString())
                ->default(function ($livewire): string {
                    if ($livewire instanceof AttendanceOverview && filled($livewire->dateTo)) {
                        return $livewire->dateTo;
                    }

                    if (method_exists($livewire, 'getTableFilterState')) {
                        $workDate = $livewire->getTableFilterState('work_date');
                        if (! empty($workDate['preset'])) {
                            $to = match ($workDate['preset']) {
                                'today'     => Carbon::today()->toDateString(),
                                'this_week' => Carbon::now()->endOfWeek()->toDateString(),
                                default     => Carbon::now()->endOfMonth()->toDateString(),
                            };

                            return Carbon::parse($to)->min(Carbon::today())->toDateString();
                        }

                        if (! empty($workDate['work_date_to'])) {
                            return $workDate['work_date_to'];
                        }

                        $punchedAt = $livewire->getTableFilterState('punched_at');
                        if (! empty($punchedAt['punched_to'])) {
                            return $punchedAt['punched_to'];
                        }
                    }

                    return Carbon::today()->toDateString();
                })
                ->rules([function (Get $get) use ($maxRangeDays): Closure {
                    return function (string $attribute, mixed $value, Closure $fail) use ($get, $maxRangeDays): void {
                        $from = $get('date_from');

                        if (! $from || ! $value) {
                            return;
                        }

                        if (AttendanceOverviewService::rangeDays($from, $value) > $maxRangeDays) {
                            $fail(__('attendance::filament/pages/overview.form.range-too-wide', [
                                'days' => $maxRangeDays,
                            ]));
                        }
                    };
                }]),
        ];
    }

    /**
     * Active filter ids of the launch page as a list, so the export modal
     * starts from exactly what the user is looking at (the page filters
     * when launched from the overview, the table filter state otherwise).
     *
     * @return list<int>
     */
    protected static function defaultFilterValues(mixed $livewire, string $field): array
    {
        $value = null;

        if ($livewire instanceof AttendanceOverview) {
            $value = match ($field) {
                'employee_id'   => $livewire->employeeId,
                'department_id' => $livewire->departmentId,
                'calendar_id'   => $livewire->calendarId,
                default         => null,
            };
        } elseif (method_exists($livewire, 'getTableFilterState')) {
            $state = $livewire->getTableFilterState($field);
            $value = $state['value'] ?? $state['values'] ?? null;
        }

        return collect((array) $value)
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Filament places the column picker above the exporter's own options.
     * Export flows ask for the filter fields first, so move the
     * `columnMap` fieldset to the end while reusing the native components.
     */
    public static function configureExportAction(ExportAction|ExportBulkAction $action): ExportAction|ExportBulkAction
    {
        $schemaContainer = $action->getSchema(Schema::make());
        $components = $schemaContainer?->getComponents() ?? [];

        if (empty($components)) {
            return $action;
        }

        $action->modalWidth('2xl');

        if ($action->hasColumnMapping()) {
            $action->columnMappingColumns(2);
        }

        return $action->schema(function (ExportAction|ExportBulkAction $action) use ($components): array {
            $current = $components;

            // A selected-rows export never applies the range/scope fields
            // (it exports exactly the chosen records), so keep only the
            // column picker instead of demanding date bounds it ignores.
            if ($action instanceof ExportBulkAction) {
                return array_values(array_filter(
                    $current,
                    fn (mixed $component): bool => $component instanceof Fieldset,
                ));
            }

            // Filament puts the columnMap fieldset first; move it last so the
            // filter fields render on top of the modal.
            if ($action->hasColumnMapping() && ($current[0] ?? null) instanceof Fieldset) {
                $current[] = array_shift($current);
            }

            return $current;
        });
    }
}
