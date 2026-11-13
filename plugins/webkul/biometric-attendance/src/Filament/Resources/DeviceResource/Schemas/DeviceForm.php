<?php

namespace Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Webkul\BiometricAttendance\Enums\PunchDirectionMode;

class DeviceForm
{
    /**
     * @var array<string, string>|null
     */
    protected static ?array $timezoneOptions = null;

    /**
     * @return array<string, string>
     */
    public static function timezoneOptions(): array
    {
        if (static::$timezoneOptions === null) {
            $identifiers = timezone_identifiers_list();
            static::$timezoneOptions = array_combine($identifiers, $identifiers);
        }

        return static::$timezoneOptions;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('biometric-attendance::filament/resources/device.form.sections.general.title'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('biometric-attendance::filament/resources/device.form.name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('company_id')
                            ->label(__('biometric-attendance::filament/resources/device.form.company'))
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn () => Auth::user()?->default_company_id),
                        Select::make('timezone')
                            ->label(__('biometric-attendance::filament/resources/device.form.timezone'))
                            ->required()
                            ->options(fn (): array => static::timezoneOptions())
                            ->default(config('app.timezone'))
                            ->preload()
                            ->searchable()
                            ->helperText(__('biometric-attendance::filament/resources/device.form.timezone-helper-text')),
                        Select::make('punch_direction_mode')
                            ->label(__('biometric-attendance::filament/resources/device.form.punch-direction-mode'))
                            ->options(PunchDirectionMode::class)
                            ->default(PunchDirectionMode::AutoToggle)
                            ->required()
                            ->helperText(__('biometric-attendance::filament/resources/device.form.punch-direction-mode-helper-text')),
                        Toggle::make('is_active')
                            ->label(__('biometric-attendance::filament/resources/device.form.active'))
                            ->inline(false)
                            ->default(true),
                    ]),
                Section::make(__('biometric-attendance::filament/resources/device.form.sections.connection.title'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('host')
                            ->label(__('biometric-attendance::filament/resources/device.form.host'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder('192.168.1.201')
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
                            ->native(false)
                            ->options([
                                'tcp' => 'TCP',
                                'udp' => 'UDP',
                            ])
                            ->default('tcp')
                            ->helperText(__('biometric-attendance::filament/resources/device.form.protocol-helper-text')),
                        TextInput::make('comm_key')
                            ->label(__('biometric-attendance::filament/resources/device.form.comm-key'))
                            ->password()
                            ->revealable()
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->formatStateUsing(fn (string $operation, mixed $state): mixed => $operation === 'edit' ? null : $state)
                            ->dehydrated(fn (mixed $state): bool => filled($state))
                            ->helperText(__('biometric-attendance::filament/resources/device.form.comm-key-helper-text')),
                    ]),
                Section::make(__('biometric-attendance::filament/resources/device.form.sections.status.title'))
                    ->columns(2)
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('last_sync_at')
                            ->label(__('biometric-attendance::filament/resources/device.form.sections.status.last-sync'))
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('last_error')
                            ->label(__('biometric-attendance::filament/resources/device.form.sections.status.last-error'))
                            ->color('danger')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
