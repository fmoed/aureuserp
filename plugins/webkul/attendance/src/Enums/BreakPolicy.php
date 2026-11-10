<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BreakPolicy: string implements HasColor, HasLabel
{
    case AutoDeduct = 'auto_deduct';

    case PunchBased = 'punch_based';

    case AllowSkipForEarlyExit = 'allow_skip_for_early_exit';

    public function getLabel(): string
    {
        return __('attendance::enums.break_policy.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AutoDeduct            => 'primary',
            self::PunchBased            => 'info',
            self::AllowSkipForEarlyExit => 'success',
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
