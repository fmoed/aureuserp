<?php

namespace Webkul\Attendance\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Employee\Models\Employee;

class AbsenceReportExporter extends Exporter
{
    protected static ?string $model = Employee::class;

    /**
     * Export scope: the summary is always computed for an explicit date
     * range (validated like the overview filters), defaulting to the
     * current month. One row per employee, matching the manual
     * absence-report shape (employee, workdays, present, absent, rate).
     *
     * @return array<int, Component>
     */
    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('employee_id')
                ->label(__('attendance::filament/exports/absence-report.options.employee'))
                ->options(fn (): array => Employee::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->nullable(),
            DatePicker::make('date_from')
                ->label(__('attendance::filament/exports/absence-report.options.date-from'))
                ->required()
                ->default(fn (): string => Carbon::today()->startOfMonth()->toDateString()),
            DatePicker::make('date_to')
                ->label(__('attendance::filament/exports/absence-report.options.date-to'))
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
        ];
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label(__('attendance::filament/exports/absence-report.columns.employee')),
            ExportColumn::make('workdays')
                ->label(__('attendance::filament/exports/absence-report.columns.workdays'))
                ->formatStateUsing(fn (Employee $record, array $options): int => self::summaryFor($record, $options)['workdays']),
            ExportColumn::make('present')
                ->label(__('attendance::filament/exports/absence-report.columns.present'))
                ->formatStateUsing(fn (Employee $record, array $options): int => self::summaryFor($record, $options)['present']),
            ExportColumn::make('absent')
                ->label(__('attendance::filament/exports/absence-report.columns.absent'))
                ->formatStateUsing(fn (Employee $record, array $options): int => self::summaryFor($record, $options)['absent']),
            ExportColumn::make('absence_rate')
                ->label(__('attendance::filament/exports/absence-report.columns.absence-rate'))
                ->formatStateUsing(fn (Employee $record, array $options): string => self::formatRate(self::summaryFor($record, $options)['absence_rate'])),
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string}  $options
     * @return array{employee_id: int, name: string, workdays: int, present: int, absent: int, on_leave: int, holidays: int, pending: int, minutes: int, absence_rate: float}
     */
    protected static function summaryFor(Employee $record, array $options): array
    {
        return app(AttendanceOverviewService::class)->summaryFor(
            $record,
            Carbon::parse($options['date_from']),
            Carbon::parse($options['date_to']),
        );
    }

    protected static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.').'%';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = __('attendance::filament/exports/absence-report.notification.completed', [
            'count' => number_format($export->successful_rows),
        ]);

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.__('attendance::filament/exports/absence-report.notification.failed', [
                'count' => number_format($failedRowsCount),
            ]);
        }

        return $body;
    }
}
