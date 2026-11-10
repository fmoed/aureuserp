<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Filament\Support\Contracts\HasIcon;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\Pages\EditCalendar;
use Webkul\Attendance\Filament\Clusters\Configurations\Resources\CalendarResource\RelationManagers\EmployeesRelationManager;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Filament\Clusters\Settings\Pages\ManageSync as ManageSyncSettings;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\CreateDevice;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\EditDevice;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\ListDevices;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\DeviceInfo;
use Webkul\BiometricAttendance\Services\DeviceSyncService;
use Webkul\BiometricAttendance\Services\PunchRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');

    if (class_exists(WriterRegistry::class) && ! WriterRegistry::isRegistered('biometric-attendance')) {
        WriterRegistry::register('biometric-attendance');
    }
});

function biometricDeviceUser(Company $company, array $permissions, bool $global = true): User
{
    $user = FilamentHelper::actingAsCompanyUser($company, $permissions, $global);

    $user->forceFill(['is_active' => true])->saveQuietly();

    return $user;
}

/**
 * @param  list<array{uid: int, user_id: string, record_time: string}>  $rows
 */
function bindHardeningSyncClient(array $rows): void
{
    app()->bind(ZktecoClientInterface::class, fn () => new class($rows) implements ZktecoClientInterface
    {
        /**
         * @param  list<array{uid: int, user_id: string, record_time: string}>  $rows
         */
        public function __construct(private array $rows) {}

        public function getDeviceInfo(Device $device): DeviceInfo
        {
            return new DeviceInfo(deviceName: 'UA300', serialNumber: 'HARDEN-SN', host: $device->host);
        }

        public function getAttendanceLogs(Device $device): array
        {
            return array_map(
                fn (array $row): PunchRecord => PunchRecord::fromArray([...$row, 'state' => '1', 'type' => '0', 'device_ip' => $device->host]),
                $this->rows,
            );
        }
    });
}

it('scopes devices to the active company and stamps creator and company', function () {
    $companyA = CompanyHelper::company();
    $companyB = CompanyHelper::company();

    $user = biometricDeviceUser($companyA, ['view_any_biometric_attendance_device']);

    $deviceA = Device::create(['name' => 'Gate A', 'host' => '10.0.0.1', 'timezone' => 'UTC']);
    Device::create(['name' => 'Gate B', 'host' => '10.0.0.2', 'timezone' => 'UTC', 'company_id' => $companyB->id]);

    expect($deviceA->company_id)->toBe($companyA->id)
        ->and($deviceA->creator_id)->toBe($user->id)
        ->and(Device::query()->pluck('id')->all())->toBe([$deviceA->id])
        ->and(Device::withoutGlobalScope(CompanyScope::class)->count())->toBe(2);
});

it('matches device users to barcodes regardless of leading zeros', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, ['view_any_biometric_attendance_device']);

    $employee = Employee::factory()->create(['company_id' => $company->id, 'barcode' => '111']);

    $device = Device::create(['name' => 'Gate', 'host' => '10.0.0.3', 'timezone' => 'UTC']);

    bindHardeningSyncClient([
        ['uid' => 1, 'user_id' => '00111', 'record_time' => '2026-10-01 08:00:00'],
    ]);

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();

    expect(DeviceSyncService::normalizeUserId('00111'))->toBe('111')
        ->and(Punch::query()->where('device_id', $device->id)->value('employee_id'))->toBe($employee->id);
});

it('keeps pushing after a single punch fails', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, ['view_any_biometric_attendance_device']);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'barcode'    => '222',
        'time_zone'  => 'UTC',
    ]);

    $brokenDevice = Device::create(['name' => 'Broken Gate', 'host' => '10.0.0.4', 'timezone' => 'Not/AZone']);
    $healthyDevice = Device::create(['name' => 'Healthy Gate', 'host' => '10.0.0.5', 'timezone' => 'UTC']);

    Punch::create([
        'device_id'   => $brokenDevice->id,
        'zk_user_id'  => '222',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-01 07:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    Punch::create([
        'device_id'   => $healthyDevice->id,
        'zk_user_id'  => '222',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-01 08:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 2,
    ]);

    $this->artisan('biometric:push-events')
        ->expectsOutputToContain('Pushed 1')
        ->assertSuccessful();

    expect(Punch::query()->where('device_uid', 2)->value('pushed_at'))->not->toBeNull()
        ->and(Punch::query()->where('device_uid', 1)->value('pushed_at'))->toBeNull();
});

it('hides device operations without the update permission', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, ['view_any_biometric_attendance_device']);

    $device = Device::create(['name' => 'Gate', 'host' => '10.0.0.7', 'timezone' => 'UTC']);

    Livewire::test(ListDevices::class)
        ->assertOk()
        ->assertTableActionHidden('testConnection', $device)
        ->assertTableActionHidden('syncNow', $device)
        ->assertActionHidden('syncAllNow');
});

it('shows device operations with the update permission', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, [
        'view_any_biometric_attendance_device',
        'update_biometric_attendance_device',
    ]);

    $device = Device::create(['name' => 'Gate', 'host' => '10.0.0.8', 'timezone' => 'UTC']);

    Livewire::test(ListDevices::class)
        ->assertOk()
        ->assertTableActionVisible('testConnection', $device)
        ->assertTableActionVisible('syncNow', $device)
        ->assertActionVisible('syncAllNow');
});

it('schedules one chained sync-all run with overlap protection', function () {
    $syncEvents = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'biometric:sync-all'))
        ->values();

    expect($syncEvents)->toHaveCount(1)
        ->and($syncEvents->first()->command)->toContain('--chain')
        ->and($syncEvents->first()->withoutOverlapping)->toBeTrue()
        ->and($syncEvents->first()->expiresAt)->toBe(30);
});

it('stamps the configured punch direction mode when pushing', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, ['view_any_biometric_attendance_device']);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'barcode'    => '555',
        'time_zone'  => 'UTC',
    ]);

    $expectations = [];

    foreach ([
        ['auto_toggle', 0, 0],
        ['force_in', 1, 0],
        ['force_out', -1, 0],
        ['strict_direction', -1, 1],
    ] as $index => [$mode, $expectedDirection, $rawType]) {
        $device = Device::create([
            'name'                 => 'Gate '.$mode,
            'host'                 => '10.0.1.'.($index + 1),
            'timezone'             => 'UTC',
            'punch_direction_mode' => $mode,
        ]);

        $punch = Punch::create([
            'device_id'   => $device->id,
            'zk_user_id'  => '555',
            'employee_id' => $employee->id,
            'punch_time'  => '2026-10-01 0'.($index + 6).':00:00',
            'raw_state'   => 0,
            'raw_type'    => $rawType,
            'device_uid'  => $index + 1,
        ]);

        $expectations['punch:'.$punch->id] = $expectedDirection;
    }

    $this->artisan('biometric:push-events')->assertSuccessful();

    $events = AttendanceEvent::query()->get()->keyBy('source_ref');

    foreach ($expectations as $sourceRef => $expectedDirection) {
        expect($events->get($sourceRef)?->direction)->toBe($expectedDirection);
    }
});

it('pushes punches across companies without the company scope hiding the employee', function () {
    $companyA = CompanyHelper::company();
    $companyB = CompanyHelper::company();

    biometricDeviceUser($companyA, ['view_any_biometric_attendance_device']);

    $device = Device::create([
        'name'     => 'Cross Company Gate',
        'host'     => '10.0.2.1',
        'timezone' => 'UTC',
    ]);

    // The employee's own partner sync cannot see a partner owned by another
    // company through CompanyScope, so the fixture is created without events.
    $employee = Employee::withoutEvents(fn (): Employee => Employee::factory()->create([
        'company_id' => $companyB->id,
        'barcode'    => '777',
        'time_zone'  => 'UTC',
    ]));

    $punch = Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '777',
        'employee_id' => $employee->id,
        'punch_time'  => '2026-10-01 08:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    $this->artisan('biometric:push-events')->assertSuccessful();

    expect($punch->refresh()->pushed_at)->not->toBeNull()
        ->and(AttendanceEvent::withoutGlobalScope(CompanyScope::class)->where('source_ref', 'punch:'.$punch->id)->value('employee_id'))
        ->toBe($employee->id);
});

it('tests the connection from the create form without persisting the device', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, [
        'view_any_biometric_attendance_device',
        'create_biometric_attendance_device',
    ]);

    bindHardeningSyncClient([]);

    $devicesBefore = Device::withoutGlobalScope(CompanyScope::class)->count();

    Livewire::test(CreateDevice::class)
        ->fillForm([
            'name'     => 'New Gate',
            'host'     => '10.5.0.1',
            'port'     => 4370,
            'protocol' => 'tcp',
            'comm_key' => 0,
        ])
        ->callAction('testConnection')
        ->assertHasNoActionErrors()
        ->assertNotified(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.title'));

    expect(Device::withoutGlobalScope(CompanyScope::class)->count())->toBe($devicesBefore);
});

it('asks for the host before probing a device from the create form', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, [
        'view_any_biometric_attendance_device',
        'create_biometric_attendance_device',
    ]);

    Livewire::test(CreateDevice::class)
        ->fillForm(['name' => 'No Host'])
        ->callAction('testConnection')
        ->assertNotified(__('biometric-attendance::filament/resources/device.table.actions.test-connection.notification.missing-host'));
});

it('exposes test connection and sync now on the device page', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, [
        'view_any_biometric_attendance_device',
        'view_biometric_attendance_device',
        'update_biometric_attendance_device',
    ]);

    $device = Device::create(['name' => 'Page Gate', 'host' => '10.3.0.1', 'timezone' => 'UTC']);

    Livewire::test(EditDevice::class, ['record' => $device->getRouteKey()])
        ->assertOk()
        ->assertActionVisible('testConnection')
        ->assertActionVisible('syncNow');
});

it('backfills unmatched punches through the shared service method', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, ['view_any_biometric_attendance_device']);

    $device = Device::create(['name' => 'Backfill Gate', 'host' => '10.3.0.3', 'timezone' => 'UTC']);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'barcode' => '950']);

    $punch = Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '0950',
        'employee_id' => null,
        'punch_time'  => '2026-10-01 09:00:00',
        'raw_state'   => 0,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    expect(app(DeviceSyncService::class)->backfillUnmatched($device))->toBe(1)
        ->and($punch->refresh()->employee_id)->toBe($employee->id);
});

it('lists assigned employees on the shift and unassigns them', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, [
        'view_any_support_calendar',
        'view_support_calendar',
        'update_support_calendar',
        'update_employee_employee',
        'view_any_employee_employee',
    ]);

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => $calendar->id,
        'user_id'     => User::factory(),
    ]);
    $unassigned = Employee::factory()->create([
        'company_id'  => $company->id,
        'calendar_id' => null,
        'user_id'     => User::factory(),
    ]);

    Livewire::test(EmployeesRelationManager::class, [
        'ownerRecord' => $calendar,
        'pageClass'   => EditCalendar::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$employee])
        ->assertCanNotSeeTableRecords([$unassigned])
        ->callTableAction('unassignFromShift', $employee);

    expect($employee->refresh()->calendar_id)->toBeNull();
});

it('limits a ui-triggered sync-all to the active company', function () {
    $companyA = CompanyHelper::company();
    $companyB = CompanyHelper::company();

    biometricDeviceUser($companyA, [
        'view_any_biometric_attendance_device',
        'update_biometric_attendance_device',
    ]);

    $deviceA = Device::create(['name' => 'Tenant A Gate', 'host' => '10.4.0.1', 'timezone' => 'UTC']);

    $deviceB = Device::create(['name' => 'Tenant B Gate', 'host' => '10.4.0.2', 'timezone' => 'UTC']);
    $deviceB->forceFill(['company_id' => $companyB->id])->saveQuietly();

    bindHardeningSyncClient([
        ['uid' => 1, 'user_id' => '123', 'record_time' => '2026-10-01 08:00:00'],
    ]);

    $this->artisan('biometric:sync-all', ['--company' => [$companyA->id]])->assertSuccessful();

    expect(Punch::withoutGlobalScope(CompanyScope::class)->where('device_id', $deviceA->id)->count())->toBe(1)
        ->and(Punch::withoutGlobalScope(CompanyScope::class)->where('device_id', $deviceB->id)->count())->toBe(0);
});

it('keeps the biometric settings sub-group iconless inside the settings cluster', function () {
    $group = ManageSyncSettings::getNavigationGroup();

    expect($group)->toBeString()
        ->and($group)->toBe(__('admin.navigation.attendance'))
        ->and($group)->not->toBeInstanceOf(HasIcon::class);
});

it('keeps the sync watermark anchored after a punch is renumbered', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, ['view_any_biometric_attendance_device']);

    Employee::factory()->create(['company_id' => $company->id, 'barcode' => '808']);

    $device = Device::create(['name' => 'Renumber Gate', 'host' => '10.6.0.1', 'timezone' => 'UTC']);

    bindHardeningSyncClient([
        ['uid' => 5, 'user_id' => '808', 'record_time' => '2026-10-01 08:00:00'],
    ]);

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();

    $punch = Punch::query()->where('device_id', $device->id)->sole();

    expect($punch->device_uid)->toBe(5)
        ->and($device->refresh()->last_sync_uid)->toBe(5);

    bindHardeningSyncClient([
        ['uid' => 9, 'user_id' => '808', 'record_time' => '2026-10-01 08:00:00'],
    ]);

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();

    expect(Punch::query()->where('device_id', $device->id)->count())->toBe(1)
        ->and($punch->refresh()->device_uid)->toBe(9)
        ->and($device->refresh()->last_sync_uid)->toBe(9);
});

it('ignores corrupt device timestamps and keeps syncing valid punches', function () {
    $company = CompanyHelper::company();

    biometricDeviceUser($company, ['view_any_biometric_attendance_device']);

    Employee::factory()->create(['company_id' => $company->id, 'barcode' => '909']);

    $device = Device::create(['name' => 'Corrupt Gate', 'host' => '10.6.0.2', 'timezone' => 'UTC']);

    bindHardeningSyncClient([
        ['uid' => 1, 'user_id' => '909', 'record_time' => '2026-10-01 08:00:00'],
        ['uid' => 2, 'user_id' => '909', 'record_time' => '0000-00-00 00:00:00'],
        ['uid' => 3, 'user_id' => '909', 'record_time' => '2026-10-01 17:00:00'],
        ['uid' => 4, 'user_id' => '909', 'record_time' => ''],
    ]);

    $this->artisan('biometric:sync', ['device' => $device->id])->assertSuccessful();

    expect(Punch::query()->where('device_id', $device->id)->count())->toBe(2)
        ->and($device->refresh()->last_sync_uid)->toBe(3)
        ->and(Device::findByIdentifier((string) $device->id)?->is($device))->toBeTrue()
        ->and(Device::findByIdentifier('Corrupt Gate')?->is($device))->toBeTrue();
});
