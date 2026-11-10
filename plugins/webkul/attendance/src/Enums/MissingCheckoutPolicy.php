<?php

namespace Webkul\Attendance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MissingCheckoutPolicy: string implements HasColor, HasLabel
{
    case IncompleteAbsent = 'incomplete_absent';

    case IncompleteHalfDay = 'incomplete_half_day';

    case AutoCreditScheduled = 'auto_credit_scheduled';

    public function getLabel(): string
    {
        return __('attendance::enums.missing_checkout_policy.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::IncompleteAbsent    => 'danger',
            self::IncompleteHalfDay   => 'warning',
            self::AutoCreditScheduled => 'info',
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
