<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RoundingMode: string implements HasColor, HasLabel
{
    case None = 'none';

    case Nearest5 = 'nearest_5';

    case Nearest15 = 'nearest_15';

    public function getLabel(): string
    {
        return __('attendance::enums.rounding_mode.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None      => 'gray',
            self::Nearest5  => 'info',
            self::Nearest15 => 'warning',
        };
    }

    public function step(): int
    {
        return match ($this) {
            self::None      => 1,
            self::Nearest5  => 5,
            self::Nearest15 => 15,
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
