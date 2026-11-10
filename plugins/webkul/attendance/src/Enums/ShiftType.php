<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShiftType: string implements HasColor, HasLabel
{
    case Fixed = 'fixed';

    case Flexible = 'flexible';

    case Split = 'split';

    case Open = 'open';

    public function getLabel(): string
    {
        return __('attendance::enums.shift_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Fixed    => 'primary',
            self::Flexible => 'info',
            self::Split    => 'warning',
            self::Open     => 'gray',
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
