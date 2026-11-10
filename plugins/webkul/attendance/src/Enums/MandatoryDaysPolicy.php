<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MandatoryDaysPolicy: string implements HasColor, HasLabel
{
    case TreatAsHoliday = 'treat_as_holiday';

    case TreatAsMandatoryWorkday = 'treat_as_mandatory_workday';

    case IgnoreInAttendance = 'ignore_in_attendance';

    public function getLabel(): string
    {
        return __('attendance::enums.mandatory_days_policy.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::TreatAsHoliday          => 'primary',
            self::TreatAsMandatoryWorkday => 'danger',
            self::IgnoreInAttendance      => 'gray',
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
