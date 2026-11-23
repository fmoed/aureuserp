<?php

namespace Webkul\RemoteAttendance\Filament\Pages;

use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\RemoteAttendance\Filament\Clusters\Settings\Pages\ManageRemoteAttendance as BaseManageRemoteAttendance;

class ManageRemoteAttendance extends BaseManageRemoteAttendance
{
    protected static ?string $cluster = Configurations::class;

    protected static ?string $slug = 'manage-remote-attendance';

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('attendance::filament/clusters/configurations.groups.settings');
    }
}
