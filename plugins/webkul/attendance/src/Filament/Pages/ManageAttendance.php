<?php

namespace Webkul\Attendance\Filament\Pages;

use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\Attendance\Filament\Clusters\Settings\Pages\ManageAttendance as BaseManageAttendance;

class ManageAttendance extends BaseManageAttendance
{
    protected static ?string $cluster = Configurations::class;

    protected static ?string $slug = 'manage-attendance';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('attendance::filament/clusters/configurations.groups.settings');
    }
}
