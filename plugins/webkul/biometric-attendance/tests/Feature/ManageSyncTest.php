<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Webkul\BiometricAttendance\Filament\Pages\ManageSync;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\ListDevices;
use Webkul\BiometricAttendance\Scheduling\SyncSchedule;
use Webkul\BiometricAttendance\Settings\BiometricSyncSettings;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');
});

function actingAsSyncManager(array $permissions = ['page_biometric_attendance_manage_sync']): void
{
    FilamentHelper::actingAs($permissions)->forceFill(['is_active' => true])->saveQuietly();
}

it('defaults the sync cadence to hourly and follows the saved setting', function () {
    expect(app(BiometricSyncSettings::class)->sync_cadence)->toBe('hourly')
        ->and(SyncSchedule::storedCadence())->toBe('hourly')
        ->and(SyncSchedule::cronExpression())->toBe('0 * * * *');

    app(BiometricSyncSettings::class)->fill(['sync_cadence' => 'daily'])->save();

    expect(SyncSchedule::storedCadence())->toBe('daily')
        ->and(SyncSchedule::cronExpression())->toBe('0 0 * * *');
});

it('renders the sync settings page with the schedule summary', function () {
    actingAsSyncManager();

    Livewire::test(ManageSync::class)
        ->assertOk()
        ->assertSee(__('biometric-attendance::filament/pages/manage-sync.form.cadence.label'))
        ->assertSee(__('biometric-attendance::filament/pages/manage-sync.form.cadence-options.hourly'))
        ->assertSee(__('biometric-attendance::filament/pages/manage-sync.info.next-run.label'))
        ->assertSee(__('biometric-attendance::filament/pages/manage-sync.info.last-sync.never'))
        ->assertActionExists('syncAllNow');
});

it('renders even when the settings row was never migrated', function () {
    actingAsSyncManager();

    DB::table('settings')->where('group', 'biometric_sync')->delete();

    Livewire::test(ManageSync::class)
        ->assertOk()
        ->assertSee(__('biometric-attendance::filament/pages/manage-sync.form.cadence-options.hourly'))
        ->assertSee(__('biometric-attendance::filament/pages/manage-sync.info.next-run.label'));
});

it('renders a native select without a floating panel', function () {
    actingAsSyncManager();

    $html = Livewire::test(ManageSync::class)->html();

    expect($html)
        ->toContain('<select')
        ->not->toContain('fi-select-input-btn');
});

it('persists the cadence from the settings page', function () {
    actingAsSyncManager();

    Livewire::test(ManageSync::class)
        ->fillForm(['sync_cadence' => 'thirty-minutes'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(BiometricSyncSettings::class)->sync_cadence)->toBe('thirty-minutes')
        ->and(SyncSchedule::cronExpression())->toBe('*/30 * * * *');
});

it('exposes sync-all-now on the devices list', function () {
    FilamentHelper::actingAs(['view_any_biometric_attendance_device'])->forceFill(['is_active' => true])->saveQuietly();

    Livewire::test(ListDevices::class)
        ->assertOk()
        ->assertActionExists('syncAllNow');
});
