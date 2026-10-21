<?php

namespace Webkul\Attendance\Filament\Exports\Concerns;

use Filament\Actions\Exports\Models\Export;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Filament\Pages\AttendanceOverview;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;

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

        if (! empty($options['employee_id'])) {
            $scope = 'emp-'.self::fileNameEmployeeSlug((int) $options['employee_id']);
        }

        return sprintf('%s_%s_to_%s_%s', static::exportFilePrefix(), $from, $to, $scope);
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

    protected static function fileNameEmployeeSlug(int $employeeId): string
    {
        $slug = (string) str((string) Employee::query()->whereKey($employeeId)->value('name'))->slug();

        return $slug !== '' ? $slug : (string) $employeeId;
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
                ->options(fn (): array => AttendanceOverviewService::scopeEligibleEmployees(Employee::query())->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->nullable()
                ->default(function ($livewire): ?int {
                    if ($livewire instanceof AttendanceOverview) {
                        return $livewire->employeeId;
                    }

                    if (method_exists($livewire, 'getTableFilterState')) {
                        $state = $livewire->getTableFilterState('employee_id');

                        return filled($state['value'] ?? null) ? (int) $state['value'] : null;
                    }

                    return null;
                }),
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
                ->rules([function (Get $get) use ($maxRangeDays): \Closure {
                    return function (string $attribute, mixed $value, \Closure $fail) use ($get, $maxRangeDays): void {
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
}
