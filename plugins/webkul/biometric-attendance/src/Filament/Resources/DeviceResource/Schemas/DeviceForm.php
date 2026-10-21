<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('biometric-attendance::filament/resources/device.form.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('host')
                    ->label(__('biometric-attendance::filament/resources/device.form.host'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('biometric-attendance::filament/resources/device.form.host-helper-text')),
                TextInput::make('port')
                    ->label(__('biometric-attendance::filament/resources/device.form.port'))
                    ->required()
                    ->numeric()
                    ->default(4370)
                    ->minValue(1)
                    ->maxValue(65535),
                Select::make('protocol')
                    ->label(__('biometric-attendance::filament/resources/device.form.protocol'))
                    ->required()
                    ->options([
                        'tcp' => 'TCP',
                        'udp' => 'UDP',
                    ])
                    ->default('tcp')
                    ->helperText(__('biometric-attendance::filament/resources/device.form.protocol-helper-text')),
                TextInput::make('comm_key')
                    ->label(__('biometric-attendance::filament/resources/device.form.comm-key'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->helperText(__('biometric-attendance::filament/resources/device.form.comm-key-helper-text')),
                Select::make('timezone')
                    ->label(__('biometric-attendance::filament/resources/device.form.timezone'))
                    ->required()
                    ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($timezone) => [$timezone => $timezone]))
                    ->default(config('app.timezone'))
                    ->preload()
                    ->searchable()
                    ->helperText(__('biometric-attendance::filament/resources/device.form.timezone-helper-text')),
                Toggle::make('is_active')
                    ->label(__('biometric-attendance::filament/resources/device.form.active'))
                    ->default(true),
            ])
            ->columns(2);
    }
}
