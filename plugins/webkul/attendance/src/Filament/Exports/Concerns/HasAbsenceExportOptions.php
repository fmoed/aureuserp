<?php

namespace Webkul\Attendance\Filament\Exports\Concerns;

use Filament\Actions\Exports\Models\Export;
use Filament\Forms\Components\DatePicker;
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
            Select::make('employee_id')
                ->label(__('attendance::filament/exports/absence-report.options.employee'))
                ->options(fn (): array => Employee::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->nullable()
                ->default(fn ($livewire): ?int => $livewire instanceof AttendanceOverview ? $livewire->employeeId : null),
            DatePicker::make('date_from')
                ->label(__('attendance::filament/exports/absence-report.options.date-from'))
                ->required()
                ->maxDate(Carbon::today()->toDateString())
                ->default(fn ($livewire): string => $livewire instanceof AttendanceOverview && filled($livewire->dateFrom)
                    ? $livewire->dateFrom
                    : Carbon::today()->startOfMonth()->toDateString()),
            DatePicker::make('date_to')
                ->label(__('attendance::filament/exports/absence-report.options.date-to'))
                ->required()
                ->afterOrEqual('date_from')
                ->maxDate(Carbon::today()->toDateString())
                ->default(fn ($livewire): string => $livewire instanceof AttendanceOverview && filled($livewire->dateTo)
                    ? $livewire->dateTo
                    : Carbon::today()->toDateString())
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
