<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EarlyArrivalPolicy: string implements HasColor, HasLabel
{
    case ClampToSchedule = 'clamp_to_schedule';

    case OffsetEarlyDeparture = 'offset_early_departure';

    case CountAsOvertime = 'count_as_overtime';

    public function getLabel(): string
    {
        return __('attendance::enums.early_arrival_policy.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ClampToSchedule      => 'gray',
            self::OffsetEarlyDeparture => 'info',
            self::CountAsOvertime      => 'success',
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
