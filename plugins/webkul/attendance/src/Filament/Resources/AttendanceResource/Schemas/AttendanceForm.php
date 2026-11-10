<?php

namespace Webkul\Attendance\Filament\Resources\AttendanceResource\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Policies\AttendancePolicy;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Employee\Models\Employee;

class AttendanceForm
{
    /**
     * @var array<int, string>
     */
    protected static array $timezoneCache = [];

    /**
     * Time zone the manual wall-clock values are interpreted in:
     * the employee's own zone, app timezone fallback. Memoized because the
     * live form asks for it on every check-in/check-out keystroke.
     */
    public static function employeeTimezone(?int $employeeId): string
    {
        if ($employeeId) {
            if (array_key_exists($employeeId, static::$timezoneCache)) {
                return static::$timezoneCache[$employeeId];
            }

            $timezone = Employee::query()->whereKey($employeeId)->value('time_zone');

            return static::$timezoneCache[$employeeId] = $timezone ?: config('app.timezone');
        }

        return config('app.timezone');
    }

    /**
     * "Today" on the employee's wall clock. The server date is wrong
     * for anyone past UTC midnight in their own zone.
     */
    public static function employeeToday(?int $employeeId): string
    {
        return Carbon::now(static::employeeTimezone($employeeId))->toDateString();
    }

    /**
     * Calendar day of a wall-clock punch, interpreted in the employee's
     * time zone (app timezone fallback). Single source of truth so the
     * form never compares a UTC default against a browser-local default.
     */
    public static function resolveWorkDate(string $checkIn, ?int $employeeId): string
    {
        return Carbon::parse($checkIn, static::employeeTimezone($employeeId))->toDateString();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label(__('attendance::filament/resources/attendance.form.employee'))
                    ->relationship(
                        'employee',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => AttendancePolicy::scopeEmployeeQuery($query),
                    )
                    ->searchable()
                    ->required()
                    ->default(fn (): ?int => request()->integer('employee_id') ?: null)
                    ->live()
                    ->disabledOn('edit')
                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                        if (! blank($get('work_date'))) {
                            return;
                        }

                        $set('work_date', static::employeeToday($state ?: null));
                    }),
                DatePicker::make('work_date')
                    ->label(__('attendance::filament/resources/attendance.form.work-date'))
                    ->required()
                    ->maxDate(fn (Get $get): string => static::employeeToday($get('employee_id')))
                    ->default(function (Get $get): ?string {
                        $requested = (string) request()->query('work_date', '');
                        if (filled($requested) && strtotime($requested)) {
                            return Carbon::parse($requested)->toDateString();
                        }

                        return $get('employee_id') ? static::employeeToday($get('employee_id')) : null;
                    })
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                        if (filled($state) && blank($get('check_in'))) {
                            $set('check_in', Carbon::parse($state)->toDateString().' 08:00:00');
                        }
                    })
                    ->disabledOn('edit')
                    ->helperText(__('attendance::filament/resources/attendance.form.work-date-hint'))
                    ->rules(fn (Get $get, ?Model $record): array => [
                        function (string $attribute, mixed $value, \Closure $fail) use ($get, $record) {
                            if (! $value || ! $get('employee_id')) {
                                return;
                            }

                            $employeeId = $get('employee_id');
                            $checkIn = $get('check_in') ? Carbon::parse($get('check_in')) : null;
                            $checkOut = $get('check_out') ? Carbon::parse($get('check_out')) : null;

                            // Disallow multiple sessions if there is already an open session
                            $openQuery = Attendance::query()
                                ->where('employee_id', $employeeId)
                                ->where('work_date', $value)
                                ->whereNull('check_out');

                            if ($record?->exists) {
                                $openQuery->whereKeyNot($record->getKey());
                            }

                            if ($openQuery->exists()) {
                                $employeeName = Employee::query()->whereKey($employeeId)->value('name');
                                $fail(__('attendance::filament/resources/attendance.form.duplicate-day', [
                                    'employee' => $employeeName,
                                    'date'     => Carbon::parse($value)->format('d/m/Y'),
                                ]));

                                return;
                            }

                            // Disallow overlapping intervals with existing closed sessions
                            if ($checkIn !== null) {
                                $existingQuery = Attendance::query()
                                    ->where('employee_id', $employeeId)
                                    ->where('work_date', $value)
                                    ->whereNotNull('check_in');

                                if ($record?->exists) {
                                    $existingQuery->whereKeyNot($record->getKey());
                                }

                                foreach ($existingQuery->cursor() as $existing) {
                                    $existIn = Carbon::parse($existing->check_in);
                                    $existOut = $existing->check_out ? Carbon::parse($existing->check_out) : null;

                                    if ($existOut === null) {
                                        if ($checkOut === null || $checkOut->gt($existIn)) {
                                            $employeeName = Employee::query()->whereKey($employeeId)->value('name');
                                            $fail(__('attendance::filament/resources/attendance.form.duplicate-day', [
                                                'employee' => $employeeName,
                                                'date'     => Carbon::parse($value)->format('d/m/Y'),
                                            ]));

                                            return;
                                        }
                                    } elseif ($checkOut === null) {
                                        if ($checkIn->lt($existOut)) {
                                            $employeeName = Employee::query()->whereKey($employeeId)->value('name');
                                            $fail(__('attendance::filament/resources/attendance.form.duplicate-day', [
                                                'employee' => $employeeName,
                                                'date'     => Carbon::parse($value)->format('d/m/Y'),
                                            ]));

                                            return;
                                        }
                                    } else {
                                        if ($checkIn->lt($existOut) && $checkOut->gt($existIn)) {
                                            $employeeName = Employee::query()->whereKey($employeeId)->value('name');
                                            $fail(__('attendance::filament/resources/attendance.form.duplicate-day', [
                                                'employee' => $employeeName,
                                                'date'     => Carbon::parse($value)->format('d/m/Y'),
                                            ]));

                                            return;
                                        }
                                    }
                                }
                            }
                        },
                    ]),
                DateTimePicker::make('check_in')
                    ->label(__('attendance::filament/resources/attendance.form.check-in'))
                    ->required()
                    ->seconds()
                    ->live(onBlur: true)
                    ->disabled(fn (?Model $record): bool => $record !== null && $record->source !== Attendance::SOURCE_MANUAL)
                    ->dehydrated(fn (?Model $record): bool => $record === null || $record->source === Attendance::SOURCE_MANUAL)
                    ->helperText(fn (Get $get): string => __('attendance::filament/resources/attendance.form.clock-hint', [
                        'zone' => static::employeeTimezone($get('employee_id')),
                    ]))
                    ->rules(fn (Get $get): array => [
                        function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                            if (! $value || ! $get('work_date')) {
                                return;
                            }

                            if (static::resolveWorkDate($value, $get('employee_id')) !== Carbon::parse($get('work_date'))->toDateString()) {
                                $fail(__('attendance::filament/resources/attendance.form.check-in-not-in-work-date'));
                            }
                        },
                    ]),
                DateTimePicker::make('check_out')
                    ->label(__('attendance::filament/resources/attendance.form.check-out'))
                    ->seconds()
                    ->live(onBlur: true)
                    ->rule('after_or_equal:check_in')
                    ->rules(fn (Get $get): array => [
                        function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                            if (! $value || ! $get('check_in')) {
                                return;
                            }

                            if (! Attendance::isValidCheckout(Carbon::parse($get('check_in')), Carbon::parse($value))) {
                                $fail(__('attendance::filament/resources/attendance.form.check-out-not-in-work-date', [
                                    'hours' => AttendanceSettings::maxShiftHours(),
                                ]));
                            }
                        },
                    ])
                    ->helperText(function (Get $get): string {
                        $hint = __('attendance::filament/resources/attendance.form.clock-hint', [
                            'zone' => static::employeeTimezone($get('employee_id')),
                        ]);

                        if (! $get('check_in') || ! $get('check_out')) {
                            return $hint;
                        }

                        $minutes = Carbon::parse($get('check_in'))->diffInMinutes(Carbon::parse($get('check_out')));

                        if ($minutes <= Attendance::LONG_SHIFT_MINUTES) {
                            return $hint;
                        }

                        return $hint.' '.__('attendance::filament/resources/attendance.form.long-shift-warning', [
                            'hours' => round($minutes / 60, 1),
                        ]);
                    }),
                TextEntry::make('source_label')
                    ->label(__('attendance::filament/resources/attendance.form.source'))
                    ->state(fn (Get $get, ?Model $record): string => Attendance::sourceDisplayName(
                        $record?->source ?? $get('source'),
                        $record?->source_label,
                    )),
                Hidden::make('source')
                    ->default('manual')
                    ->dehydrated(false),
            ])
            ->columns(2);
    }
}
