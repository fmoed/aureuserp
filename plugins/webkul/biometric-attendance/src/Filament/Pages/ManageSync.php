<?php

namespace Webkul\BiometricAttendance\Filament\Pages;

use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\BiometricAttendance\Filament\Clusters\Settings\Pages\ManageSync as BaseManageSync;

class ManageSync extends BaseManageSync
{
    protected static ?string $cluster = Configurations::class;

    protected static ?string $slug = 'manage-sync';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('attendance::filament/clusters/configurations.groups.settings');
    }
}
