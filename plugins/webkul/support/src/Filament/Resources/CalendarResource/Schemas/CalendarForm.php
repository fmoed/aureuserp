<?php

namespace Webkul\Support\Filament\Resources\CalendarResource\Schemas;

use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Enums\PairingMode;
use Webkul\Attendance\Enums\ShiftType;
use Webkul\Support\Enums\DayOfWeek;
use Webkul\Support\Enums\DayPeriod;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;

class CalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        $isNotOpen = fn (Get $get): bool => ($get('shift_type') ?? ShiftType::Fixed->value) !== ShiftType::Open->value;
        $isFixedOrSplit = fn (Get $get): bool => in_array($get('shift_type') ?? ShiftType::Fixed->value, [ShiftType::Fixed->value, ShiftType::Split->value], true);
        $isSplit = fn (Get $get): bool => ($get('shift_type') ?? ShiftType::Fixed->value) === ShiftType::Split->value;

        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Group::make()
                            ->schema([
                                Section::make(__('support::filament/resources/calendar.form.sections.general.title'))
                                    ->schema([
                                        TextInput::make('name')
                                            ->label(__('support::filament/resources/calendar.form.sections.general.fields.schedule-name'))
                                            ->maxLength(255)
                                            ->required()
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('support::filament/resources/calendar.form.sections.general.fields.schedule-name-tooltip')),
                                        Select::make('timezone')
                                            ->label(__('support::filament/resources/calendar.form.sections.general.fields.timezone'))
                                            ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($timezone) => [$timezone => $timezone]))
                                            ->default(date_default_timezone_get())
                                            ->preload()
                                            ->searchable()
                                            ->required()
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('support::filament/resources/calendar.form.sections.general.fields.timezone-tooltip')),
                                        Select::make('company_id')
                                            ->label(__('support::filament/resources/calendar.form.sections.general.fields.company'))
                                            ->relationship('company', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->default(current_company_id()),
                                        Select::make('shift_type')
                                            ->label(__('support::filament/resources/calendar.form.sections.general.fields.shift-type'))
                                            ->options(ShiftType::options())
                                            ->default(ShiftType::Fixed->value)
                                            ->required()
                                            ->native(false)
                                            ->live()
                                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                                if ($state === ShiftType::Flexible->value) {
                                                    $set('flexible_hours', true);
                                                } elseif ($state === ShiftType::Fixed->value) {
                                                    $set('flexible_hours', false);
                                                }

                                                if ($state === ShiftType::Split->value) {
                                                    $set('pairing_mode', PairingMode::EveryInOutPair->value);

                                                    $periods = array_values((array) ($get('shift_periods') ?? []));

                                                    if (count($periods) < 2) {
                                                        $set('shift_periods', [
                                                            $periods[0] ?? ['day_period' => DayPeriod::Morning->value, 'hour_from' => null, 'hour_to' => null],
                                                            ['day_period' => DayPeriod::Afternoon->value, 'hour_from' => null, 'hour_to' => null],
                                                        ]);
                                                    }
                                                } else {
                                                    $set('pairing_mode', PairingMode::FirstInLastOut->value);
                                                }
                                            }),
                                    ])->columns(2),

                                Section::make(__('support::filament/resources/calendar.form.sections.configuration.title'))
                                    ->schema([
                                        TextInput::make('hours_per_day')
                                            ->label(__('support::filament/resources/calendar.form.sections.configuration.fields.hours-per-day'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(24)
                                            ->default(8)
                                            ->live(onBlur: true)
                                            ->suffix(__('support::filament/resources/calendar.form.sections.configuration.fields.hours-per-day-suffix'))
                                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                                $workDays = array_values(array_filter((array) ($get('work_days') ?? [])));
                                                if (count($workDays) > 0 && is_numeric($state)) {
                                                    $set('full_time_required_hours', round((float) $state * count($workDays), 2));
                                                }
                                            })
                                            ->rules([
                                                fn (Get $get, ?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                                    static::validatePeriodsAgainstHoursPerDay($get, $value, $fail, $record);
                                                },
                                            ]),
                                        TextInput::make('full_time_required_hours')
                                            ->label(__('support::filament/resources/calendar.form.sections.configuration.fields.full-time-required-hours'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(168)
                                            ->default(40)
                                            ->suffix(__('support::filament/resources/calendar.form.sections.configuration.fields.full-time-required-hours-suffix')),
                                        CheckboxList::make('work_days')
                                            ->label(__('support::filament/resources/calendar.form.sections.configuration.fields.work-days'))
                                            ->options(DayOfWeek::options())
                                            ->columns(4)
                                            ->columnSpanFull()
                                            ->visible($isNotOpen)
                                            ->dehydrated(false)
                                            ->live()
                                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateHoursState($get, $set))
                                            ->afterStateHydrated(function (CheckboxList $component, ?Model $record): void {
                                                if ($record instanceof Calendar) {
                                                    $component->state(static::extractWorkDays($record));
                                                }
                                            }),
                                        Repeater::make('shift_periods')
                                            ->label(__('support::filament/resources/calendar.form.sections.configuration.fields.shift-periods'))
                                            ->visible($isNotOpen)
                                            ->dehydrated(false)
                                            ->live()
                                            ->helperText(fn (?Model $record): ?string => $record instanceof Calendar && static::hasCustomPerDaySchedule($record)
                                                ? __('support::filament/resources/calendar.form.sections.configuration.custom-schedule-hint')
                                                : null)
                                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateHoursState($get, $set))
                                            ->columnSpanFull()
                                            ->defaultItems(1)
                                            ->addable($isSplit)
                                            ->deletable($isSplit)
                                            ->reorderable(false)
                                            ->addActionLabel(__('support::filament/resources/calendar.form.sections.configuration.fields.add-period'))
                                            ->afterStateHydrated(function (Repeater $component, ?Model $record): void {
                                                if ($record instanceof Calendar) {
                                                    $periods = static::extractShiftPeriods($record);

                                                    if ($periods !== []) {
                                                        $component->state($periods);
                                                    }
                                                }
                                            })
                                            ->schema([
                                                Select::make('day_period')
                                                    ->label(__('support::filament/resources/calendar.form.sections.configuration.fields.day-period'))
                                                    ->options(collect(DayPeriod::options())->except('lunch')->all())
                                                    ->default(DayPeriod::Morning->value)
                                                    ->native(false),
                                                TimePicker::make('hour_from')
                                                    ->label(__('support::filament/resources/calendar.form.sections.configuration.fields.period-from'))
                                                    ->seconds(false)
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateHoursState($get, $set)),
                                                TimePicker::make('hour_to')
                                                    ->label(__('support::filament/resources/calendar.form.sections.configuration.fields.period-to'))
                                                    ->seconds(false)
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(fn (Get $get, Set $set) => static::recalculateHoursState($get, $set)),
                                            ])
                                            ->columns(3),
                                    ])->columns(2),

                                Section::make(__('support::filament/resources/calendar.form.sections.policies.title'))
                                    ->description(__('support::filament/resources/calendar.form.sections.policies.description'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('check_in_window_before_minutes')
                                            ->label(__('support::filament/resources/calendar.form.sections.windows.fields.check-in-before'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(720)
                                            ->default(120)
                                            ->visible($isNotOpen)
                                            ->live(onBlur: true)
                                            ->dehydrated(true)
                                            ->suffix(__('support::filament/resources/calendar.form.sections.windows.minutes-suffix'))
                                            ->helperText(fn (Get $get): ?string => static::windowPreviewText($get, 'in')),
                                        TextInput::make('check_out_window_after_minutes')
                                            ->label(__('support::filament/resources/calendar.form.sections.windows.fields.check-out-after'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(720)
                                            ->default(240)
                                            ->visible($isNotOpen)
                                            ->live(onBlur: true)
                                            ->dehydrated(true)
                                            ->suffix(__('support::filament/resources/calendar.form.sections.windows.minutes-suffix'))
                                            ->helperText(fn (Get $get): ?string => static::windowPreviewText($get, 'out'))
                                            ->rules([
                                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                                    $before = (int) ($get('check_in_window_before_minutes') ?? 120);
                                                    $after = (int) ($value ?? 240);
                                                    $shift = (float) ($get('hours_per_day') ?? 8) * 60;

                                                    if ($before + $after + $shift > 1440) {
                                                        $fail(__('support::filament/resources/calendar.form.sections.windows.overlap-error'));
                                                    }
                                                },
                                            ]),
                                        TextInput::make('late_grace_minutes')
                                            ->label(__('support::filament/resources/calendar.form.sections.punctuality.fields.late-grace'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(240)
                                            ->default(15)
                                            ->visible($isFixedOrSplit)
                                            ->dehydrated(true)
                                            ->suffix(__('support::filament/resources/calendar.form.sections.windows.minutes-suffix')),
                                        TextInput::make('early_leave_grace_minutes')
                                            ->label(__('support::filament/resources/calendar.form.sections.punctuality.fields.early-leave-grace'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(240)
                                            ->default(10)
                                            ->visible($isFixedOrSplit)
                                            ->dehydrated(true)
                                            ->suffix(__('support::filament/resources/calendar.form.sections.windows.minutes-suffix')),
                                        Select::make('missing_checkout_policy')
                                            ->label(__('support::filament/resources/calendar.form.sections.policies.fields.missing-checkout-policy'))
                                            ->options(MissingCheckoutPolicy::options())
                                            ->default(MissingCheckoutPolicy::IncompleteAbsent->value)
                                            ->afterStateHydrated(function (Select $component, mixed $state): void {
                                                if (blank($state)) {
                                                    $component->state(MissingCheckoutPolicy::IncompleteAbsent->value);
                                                }
                                            })
                                            ->required()
                                            ->visible($isNotOpen)
                                            ->dehydrated(true)
                                            ->native(false),
                                        Toggle::make('overtime_enabled')
                                            ->label(__('support::filament/resources/calendar.form.sections.policies.fields.overtime-enabled'))
                                            ->default(true)
                                            ->live()
                                            ->dehydrated(true)
                                            ->inline(false),
                                        TextInput::make('min_overtime_minutes')
                                            ->label(__('support::filament/resources/calendar.form.sections.policies.fields.min-overtime'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(240)
                                            ->default(30)
                                            ->visible(fn (Get $get): bool => (bool) ($get('overtime_enabled') ?? true))
                                            ->dehydrated(true)
                                            ->suffix(__('support::filament/resources/calendar.form.sections.windows.minutes-suffix')),
                                    ]),
                            ])
                            ->columnSpan(['lg' => 2]),
                        Group::make()
                            ->schema([
                                Section::make(__('support::filament/resources/calendar.form.sections.flexibility.title'))
                                    ->schema([
                                        Toggle::make('is_active')
                                            ->label(__('support::filament/resources/calendar.form.sections.flexibility.fields.status'))
                                            ->default(true)
                                            ->inline(false),
                                        Toggle::make('two_weeks_calendar')
                                            ->label(__('support::filament/resources/calendar.form.sections.flexibility.fields.two-weeks-calendar'))
                                            ->inline(false)
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Enable alternating two-week work schedule'),
                                        Hidden::make('flexible_hours')
                                            ->dehydrated(true)
                                            ->afterStateHydrated(fn (Hidden $component, ?Model $record) => $component->state(
                                                $record instanceof Calendar && $record->shift_type === ShiftType::Flexible
                                            )),
                                        Hidden::make('pairing_mode')
                                            ->default(PairingMode::FirstInLastOut->value)
                                            ->dehydrated(true)
                                            ->afterStateHydrated(fn (Hidden $component, ?Model $record) => $component->state(
                                                $record instanceof Calendar && $record->shift_type === ShiftType::Split
                                                    ? PairingMode::EveryInOutPair->value
                                                    : ($record?->pairing_mode?->value ?? PairingMode::FirstInLastOut->value)
                                            )),
                                    ]),
                            ])
                            ->columnSpan(['lg' => 1]),
                    ])
                    ->columns(3),
            ])
            ->columns(1);
    }

    /**
     * Validate that filled daily shift periods match `hours_per_day` (and
     * that a split shift defines at least 2 working periods).
     */
    public static function validatePeriodsAgainstHoursPerDay(Get $get, mixed $hoursPerDay, Closure $fail, ?Model $record = null): void
    {
        $shiftType = (string) ($get('shift_type') ?? ShiftType::Fixed->value);

        if ($shiftType === ShiftType::Open->value) {
            return;
        }

        $periods = static::filledPeriods((array) ($get('shift_periods') ?? []));

        if ($periods === []) {
            return;
        }

        if ($shiftType === ShiftType::Split->value && count($periods) < 2) {
            $fail(__('support::filament/resources/calendar.form.sections.configuration.split-min-periods-error'));

            return;
        }

        if ($record instanceof Calendar && static::hasCustomPerDaySchedule($record)) {
            $existingPeriods = static::extractShiftPeriods($record);

            if ($periods === $existingPeriods) {
                return;
            }
        }

        $grossMinutes = 0;

        foreach ($periods as $period) {
            $grossMinutes += static::periodDurationMinutes($period['hour_from'], $period['hour_to']);
        }

        $expectedMinutes = (int) round(((float) $hoursPerDay) * 60);

        if (abs($grossMinutes - $expectedMinutes) > 1) {
            $fail(__('support::filament/resources/calendar.form.sections.configuration.hours-mismatch-error', [
                'actual'   => rtrim(rtrim(number_format($grossMinutes / 60, 2, '.', ''), '0'), '.'),
                'expected' => rtrim(rtrim(number_format($expectedMinutes / 60, 2, '.', ''), '0'), '.'),
            ]));
        }
    }

    /**
     * Live clock preview explaining what the check-in / check-out window
     * minutes actually mean on the shift's wall clock.
     */
    public static function windowPreviewText(Get $get, string $direction): ?string
    {
        $periods = static::filledPeriods((array) ($get('shift_periods') ?? []));

        if ($periods === []) {
            return null;
        }

        $before = max(0, (int) ($get('check_in_window_before_minutes') ?? 120));
        $after = max(0, (int) ($get('check_out_window_after_minutes') ?? 240));

        if (count($periods) === 1) {
            $fromMin = (int) round(CalendarAttendance::minutesOfDay($periods[0]['hour_from']));
            $toMin = (int) round(CalendarAttendance::minutesOfDay($periods[0]['hour_to']));

            if ($direction === 'in') {
                return __('support::filament/resources/calendar.form.sections.windows.preview-in', [
                    'start' => static::formatClockMinutes($fromMin),
                    'open'  => static::formatClockMinutes($fromMin - $before),
                ]);
            }

            return __('support::filament/resources/calendar.form.sections.windows.preview-out', [
                'end'   => static::formatClockMinutes($toMin),
                'close' => static::formatClockMinutes($toMin + $after),
            ]);
        }

        $parts = [];

        foreach ($periods as $index => $period) {
            $fromMin = (int) round(CalendarAttendance::minutesOfDay($period['hour_from']));
            $toMin = (int) round(CalendarAttendance::minutesOfDay($period['hour_to']));

            $openMin = $fromMin - $before;
            $closeMin = ($toMin <= $fromMin && $fromMin > 0 ? $toMin + 1440 : $toMin) + $after;

            if ($index > 0) {
                $prevFrom = (int) round(CalendarAttendance::minutesOfDay($periods[$index - 1]['hour_from']));
                $prevTo = (int) round(CalendarAttendance::minutesOfDay($periods[$index - 1]['hour_to']));
                $prevEnd = $prevTo <= $prevFrom && $prevFrom > 0 ? $prevTo + 1440 : $prevTo;

                if ($fromMin > $prevEnd) {
                    $midpoint = (int) floor(($prevEnd + $fromMin) / 2);
                    $openMin = max($openMin, $midpoint);
                }
            }

            if ($index + 1 < count($periods)) {
                $nextFrom = (int) round(CalendarAttendance::minutesOfDay($periods[$index + 1]['hour_from']));
                $currentEnd = $toMin <= $fromMin && $fromMin > 0 ? $toMin + 1440 : $toMin;

                if ($nextFrom > $currentEnd) {
                    $midpoint = (int) floor(($currentEnd + $nextFrom) / 2);
                    $closeMin = min($closeMin, $midpoint);
                }
            }

            $parts[] = __('support::filament/resources/calendar.form.sections.windows.preview-split', [
                'index' => $index + 1,
                'start' => static::formatClockMinutes($fromMin),
                'end'   => static::formatClockMinutes($toMin),
                'open'  => static::formatClockMinutes($openMin),
                'close' => static::formatClockMinutes($closeMin),
            ]);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<int|string, mixed>  $rawPeriods
     * @return list<array{day_period: string, hour_from: string, hour_to: string}>
     */
    public static function filledPeriods(array $rawPeriods): array
    {
        $filled = [];

        foreach ($rawPeriods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $from = trim((string) ($period['hour_from'] ?? ''));
            $to = trim((string) ($period['hour_to'] ?? ''));

            if ($from === '' || $to === '') {
                continue;
            }

            $filled[] = [
                'day_period' => (string) ($period['day_period'] ?? DayPeriod::Morning->value),
                'hour_from'  => $from,
                'hour_to'    => $to,
            ];
        }

        return $filled;
    }

    public static function periodDurationMinutes(string $hourFrom, string $hourTo): int
    {
        $from = (int) round(CalendarAttendance::minutesOfDay($hourFrom));
        $to = (int) round(CalendarAttendance::minutesOfDay($hourTo));

        if ($to <= $from && $from > 0) {
            $to += 1440;
        }

        return max(0, $to - $from);
    }

    protected static function formatClockMinutes(int $minutes): string
    {
        $normalized = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($normalized, 60), $normalized % 60);
    }

    /**
     * @return list<string>
     */
    public static function extractWorkDays(Calendar $calendar): array
    {
        return $calendar->attendance()
            ->whereNull('display_type')
            ->whereNull('resource_id')
            ->whereNull('date_from')
            ->whereNull('date_to')
            ->where('day_period', '!=', 'lunch')
            ->pluck('day_of_week')
            ->map(fn (mixed $day): string => strtolower((string) $day))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{day_period: string, hour_from: string, hour_to: string}>
     */
    public static function extractShiftPeriods(Calendar $calendar): array
    {
        $rows = $calendar->attendance()
            ->whereNull('display_type')
            ->whereNull('resource_id')
            ->whereNull('date_from')
            ->whereNull('date_to')
            ->where('day_period', '!=', 'lunch')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $firstDay = $rows->first()->day_of_week;

        return $rows
            ->where('day_of_week', $firstDay)
            ->map(fn (CalendarAttendance $row): array => [
                'day_period' => (string) ($row->day_period ?: DayPeriod::Morning->value),
                'hour_from'  => (string) $row->hour_from,
                'hour_to'    => (string) $row->hour_to,
            ])
            ->values()
            ->all();
    }

    public static function recalculateHoursState(Get $get, Set $set): void
    {
        $shiftType = $get('shift_type');
        $shiftTypeValue = $shiftType instanceof ShiftType ? $shiftType->value : (string) $shiftType;

        if ($shiftTypeValue === ShiftType::Open->value) {
            return;
        }

        $periods = static::filledPeriods((array) ($get('shift_periods') ?? []));
        if ($periods === []) {
            return;
        }

        $grossMinutes = 0;
        foreach ($periods as $period) {
            $grossMinutes += static::periodDurationMinutes($period['hour_from'], $period['hour_to']);
        }

        if ($grossMinutes <= 0) {
            return;
        }

        $dailyHours = round($grossMinutes / 60, 2);
        $set('hours_per_day', $dailyHours);

        $workDays = array_values(array_filter((array) ($get('work_days') ?? [])));
        $daysCount = count($workDays);
        if ($daysCount > 0) {
            $weeklyHours = round(($grossMinutes * $daysCount) / 60, 2);
            $set('full_time_required_hours', $weeklyHours);
        }
    }

    public static function hasCustomPerDaySchedule(Calendar $calendar): bool
    {
        $rows = $calendar->attendance()
            ->whereNull('display_type')
            ->whereNull('resource_id')
            ->whereNull('date_from')
            ->whereNull('date_to')
            ->where('day_period', '!=', 'lunch')
            ->get();

        if ($rows->count() <= 1) {
            return false;
        }

        $grouped = $rows->groupBy('day_of_week');
        $firstPattern = null;

        foreach ($grouped as $dayRows) {
            $pattern = $dayRows->map(fn (CalendarAttendance $r): string => "{$r->day_period}:{$r->hour_from}-{$r->hour_to}")->sort()->values()->toJson();
            if ($firstPattern === null) {
                $firstPattern = $pattern;
            } elseif ($firstPattern !== $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Synchronize the inline `work_days` × `shift_periods` builder into
     * `calendar_attendances` when both are provided in the form state.
     *
     * @param  array<string, mixed>  $rawState
     */
    public static function syncSchedulePeriods(Calendar $calendar, array $rawState): void
    {
        if ($calendar->shift_type === ShiftType::Open) {
            return;
        }

        $periods = static::filledPeriods((array) ($rawState['shift_periods'] ?? []));
        $workDays = array_values(array_filter((array) ($rawState['work_days'] ?? [])));

        if ($periods === [] || $workDays === []) {
            return;
        }

        if (static::hasCustomPerDaySchedule($calendar)) {
            $existingDays = static::extractWorkDays($calendar);
            $existingPeriods = static::extractShiftPeriods($calendar);

            $sortedWorkDays = $workDays;
            $sortedExistingDays = $existingDays;
            sort($sortedWorkDays);
            sort($sortedExistingDays);

            if ($sortedWorkDays === $sortedExistingDays && $periods === $existingPeriods) {
                return;
            }
        }

        $calendar->attendance()
            ->whereNull('display_type')
            ->whereNull('resource_id')
            ->whereNull('date_from')
            ->whereNull('date_to')
            ->where('day_period', '!=', 'lunch')
            ->delete();

        $sort = 1;

        foreach ($workDays as $day) {
            foreach ($periods as $index => $period) {
                CalendarAttendance::create([
                    'calendar_id' => $calendar->getKey(),
                    'sort'        => $sort++,
                    'name'        => ucfirst((string) $day).' '.($index + 1),
                    'day_of_week' => strtolower((string) $day),
                    'day_period'  => $period['day_period'],
                    'hour_from'   => $period['hour_from'],
                    'hour_to'     => $period['hour_to'],
                ]);
            }
        }
    }
}
