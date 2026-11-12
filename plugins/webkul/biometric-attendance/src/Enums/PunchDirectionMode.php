<?php

namespace Webkul\BiometricAttendance\Enums;

use Filament\Support\Contracts\HasLabel;

enum PunchDirectionMode: string implements HasLabel
{
    case AutoToggle = 'auto_toggle';

    case StrictDirection = 'strict_direction';

    case ForceIn = 'force_in';

    case ForceOut = 'force_out';

    public function getLabel(): string
    {
        return match ($this) {
            self::AutoToggle      => __('biometric-attendance::filament/resources/device.form.punch-direction-modes.auto-toggle'),
            self::StrictDirection => __('biometric-attendance::filament/resources/device.form.punch-direction-modes.strict-direction'),
            self::ForceIn         => __('biometric-attendance::filament/resources/device.form.punch-direction-modes.force-in'),
            self::ForceOut        => __('biometric-attendance::filament/resources/device.form.punch-direction-modes.force-out'),
        };
    }
}
