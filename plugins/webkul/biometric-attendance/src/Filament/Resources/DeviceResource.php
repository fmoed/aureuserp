<?php

namespace Webkul\BiometricAttendance\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Webkul\Attendance\Filament\Clusters\Configurations;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\CreateDevice;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\EditDevice;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\ListDevices;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\RelationManagers\PunchesRelationManager;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Schemas\DeviceForm;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Tables\DevicesTable;
use Webkul\BiometricAttendance\Models\Device;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('biometric-attendance::filament/resources/device.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('biometric-attendance::filament/resources/device.navigation.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('attendance::filament/clusters/configurations.groups.schedules-and-devices');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'host'];
    }

    public static function form(Schema $schema): Schema
    {
        return DeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PunchesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'edit'   => EditDevice::route('/{record}/edit'),
        ];
    }
}
