<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PairingMode: string implements HasColor, HasLabel
{
    case FirstInLastOut = 'first_in_last_out';

    case EveryInOutPair = 'every_in_out_pair';

    case StrictIntervals = 'strict_intervals';

    public function getLabel(): string
    {
        return __('attendance::enums.pairing_mode.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FirstInLastOut  => 'primary',
            self::EveryInOutPair  => 'info',
            self::StrictIntervals => 'warning',
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
