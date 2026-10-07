<?php

namespace Webkul\Attendance\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Panel;
use Webkul\Support\Enums\NavigationGroup;

class Configurations extends Cluster
{
    protected static ?int $navigationSort = 4;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'attendance/configurations';
    }

    public static function getNavigationLabel(): string
    {
        return __('attendance::filament/clusters/configurations.navigation.title');
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }
}
