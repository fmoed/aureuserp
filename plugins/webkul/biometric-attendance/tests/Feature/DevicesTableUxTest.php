<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Livewire\Livewire;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\ListDevices;
use Webkul\BiometricAttendance\Models\Device;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');
});

function createUxDevice(array $overrides = []): Device
{
    return Device::create(array_merge([
        'name'      => 'UX Test Gate',
        'host'      => '192.168.1.240',
        'port'      => 4370,
        'protocol'  => 'tcp',
        'comm_key'  => 0,
        'timezone'  => 'Asia/Riyadh',
        'is_active' => true,
    ], $overrides));
}

it('shows the last device error inline and links rows to the edit page', function () {
    FilamentHelper::actingAs(['view_any_biometric_attendance_device']);

    createUxDevice([
        'last_sync_at' => '2026-10-01 08:00:00',
        'last_error'   => 'Device link failure: connect timeout after 5 seconds',
    ]);

    $component = Livewire::test(ListDevices::class);

    $table = $component->instance()->getTable();
    $columns = collect($table->getColumns());

    $errorColumn = $columns->first(fn (object $column): bool => $column->getName() === 'last_error');
    $syncColumn = $columns->first(fn (object $column): bool => $column->getName() === 'last_sync_at');

    $device = Device::query()->firstOrFail();

    expect($errorColumn)->not->toBeNull()
        ->and($errorColumn->record($device)->getColor($device->last_error))->toBe('danger')
        ->and($errorColumn->record($device)->getTooltip())->toBe($device->last_error)
        ->and($syncColumn)->not->toBeNull()
        ->and($syncColumn->record($device)->getTooltip($device->last_sync_at))->not->toBeNull();

    $component
        ->assertSee('Device link failure')
        ->assertSee(DeviceResource::getUrl('edit', ['record' => $device]), false);
});

it('keeps device rows inert without the view permission', function () {
    createUxDevice(['last_error' => 'boom']);

    // No login at all: the list page itself is denied by policy.
    Livewire::test(ListDevices::class)->assertForbidden();
});
