<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum HolidayPolicy: string implements HasColor, HasLabel
{
    case ExcuseAllApplicable = 'excuse_all_applicable';

    case ExcuseShiftOnly = 'excuse_shift_only';

    case WorkRequired = 'work_required';

    public function getLabel(): string
    {
        return __('attendance::enums.holiday_policy.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ExcuseAllApplicable => 'primary',
            self::ExcuseShiftOnly     => 'info',
            self::WorkRequired        => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }
}
