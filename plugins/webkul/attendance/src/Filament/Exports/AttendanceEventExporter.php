<?php

namespace Webkul\Attendance\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource\Tables\AttendanceEventsTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;

class AttendanceEventExporter extends Exporter
{
    protected static ?string $model = AttendanceEvent::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('employee.name')
                ->label(__('attendance::filament/exports/attendance-event.columns.employee')),
            ExportColumn::make('punched_at')
                ->label(__('attendance::filament/exports/attendance-event.columns.punched-at'))
                ->formatStateUsing(fn ($state, AttendanceEvent $record): string => AttendanceEventsTable::eventWallClock($record)?->format('Y-m-d H:i:s') ?? ''),
            ExportColumn::make('direction')
                ->label(__('attendance::filament/exports/attendance-event.columns.direction'))
                ->formatStateUsing(fn ($state, AttendanceEvent $record): string => AttendanceEvent::directionLabel($record->direction)),
            ExportColumn::make('source')
                ->label(__('attendance::filament/exports/attendance-event.columns.source'))
                ->formatStateUsing(fn (?string $state, AttendanceEvent $record): string => Attendance::sourceDisplayName($state ?? $record->source, $record->source_label)),
            ExportColumn::make('attendance.check_in')
                ->label(__('attendance::filament/exports/attendance-event.columns.linked-row'))
                ->formatStateUsing(fn ($state, AttendanceEvent $record): string => AttendanceEventsTable::linkedCheckIn($record)?->format('Y-m-d H:i:s') ?? ''),
            ExportColumn::make('pushed')
                ->label(__('attendance::filament/exports/attendance-event.columns.pushed'))
                ->formatStateUsing(fn ($state, AttendanceEvent $record): string => $record->attendance_id === null
                    ? __('attendance::filament/exports/attendance-event.pushed.pending')
                    : __('attendance::filament/exports/attendance-event.pushed.paired')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = __('attendance::filament/exports/attendance-event.notification.completed', [
            'count' => number_format($export->successful_rows),
        ]);

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.__('attendance::filament/exports/attendance-event.notification.failed', [
                'count' => number_format($failedRowsCount),
            ]);
        }

        return $body;
    }
}
