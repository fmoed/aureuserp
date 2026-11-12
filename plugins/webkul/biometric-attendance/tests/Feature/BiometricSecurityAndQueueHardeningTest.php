<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\BiometricAttendance\Enums\PunchDirectionMode;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\EditDevice;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\Pages\ListDevices;
use Webkul\BiometricAttendance\Filament\Resources\DeviceResource\RelationManagers\PunchesRelationManager;
use Webkul\BiometricAttendance\Jobs\SyncAllDevicesJob;
use Webkul\BiometricAttendance\Jobs\SyncEmployeeRetroactivelyJob;
use Webkul\BiometricAttendance\Models\Device;
use Webkul\BiometricAttendance\Models\Punch;
use Webkul\BiometricAttendance\Services\DeviceSyncService;
use Webkul\BiometricAttendance\Services\SyncPipeline;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');
    TestBootstrapHelper::ensurePluginInstalled('biometric-attendance');

    if (class_exists(WriterRegistry::class) && ! WriterRegistry::isRegistered('biometric-attendance')) {
        WriterRegistry::register('biometric-attendance');
    }
});

it('isolates backfillForEmployee to the employee company and never steals another company orphan punch', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $deviceA = Device::create([
        'name'       => 'Device A',
        'host'       => '10.10.1.10',
        'timezone'   => 'UTC',
        'is_active'  => true,
        'company_id' => $companyA->id,
    ]);

    $deviceB = Device::create([
        'name'       => 'Device B',
        'host'       => '10.10.2.10',
        'timezone'   => 'UTC',
        'is_active'  => true,
        'company_id' => $companyB->id,
    ]);

    $punchA = Punch::create([
        'device_id'  => $deviceA->id,
        'zk_user_id' => '101',
        'punch_time' => '2026-10-01 08:00:00',
        'raw_state'  => 1,
        'raw_type'   => 0,
        'device_uid' => 1,
    ]);

    $punchB = Punch::create([
        'device_id'  => $deviceB->id,
        'zk_user_id' => '101',
        'punch_time' => '2026-10-01 08:05:00',
        'raw_state'  => 1,
        'raw_type'   => 0,
        'device_uid' => 1,
    ]);

    Queue::fake();

    $employeeA = Employee::factory()->create([
        'company_id' => $companyA->id,
        'barcode'    => '101',
        'is_active'  => true,
    ]);

    $linked = app(DeviceSyncService::class)->backfillForEmployee($employeeA);

    expect($linked)->toBe(1)
        ->and($punchA->fresh()->employee_id)->toBe($employeeA->id)
        ->and($punchB->fresh()->employee_id)->toBeNull();
});

it('returns a zeroed summary safely without TypeError when syncDevice lock is already held', function () {
    $company = Company::factory()->create();

    $device = Device::create([
        'name'       => 'Locked Device',
        'host'       => '10.10.3.10',
        'timezone'   => 'UTC',
        'is_active'  => true,
        'company_id' => $company->id,
    ]);

    $lock = Cache::lock("biometric:sync-device:{$device->id}", 120);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(SyncPipeline::class)->syncDevice($device);

        expect($result)->toBe([
            'stored'      => 0,
            'skipped'     => 0,
            'backfilled'  => 0,
            'fetched'     => 0,
            'pushed'      => 0,
            'push_failed' => 0,
        ])->and($device->fresh()->last_error)->toBeNull();
    } finally {
        $lock->release();
    }
});

it('dispatches queued jobs for SyncAllNowAction and Employee barcode updates', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $user = FilamentHelper::actingAsCompanyUser($company, [
        'view_any_biometric_attendance_device',
        'update_biometric_attendance_device',
        'page_biometric_attendance_manage_sync',
    ]);
    $user->forceFill(['is_active' => true])->saveQuietly();

    Livewire::test(ListDevices::class)
        ->callAction('syncAllNow');

    Queue::assertPushed(SyncAllDevicesJob::class);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'barcode'    => null,
        'is_active'  => true,
    ]);

    $employee->update(['barcode' => '777']);

    Queue::assertPushed(SyncEmployeeRetroactivelyJob::class, function (SyncEmployeeRetroactivelyJob $job) use ($employee): bool {
        return $job->employeeId === $employee->id;
    });
});

it('hides rematchUnmatched from read-only device users on PunchesRelationManager', function () {
    $company = Company::factory()->create();

    $device = Device::create([
        'name'       => 'Auth Device',
        'host'       => '10.10.4.10',
        'timezone'   => 'UTC',
        'is_active'  => true,
        'company_id' => $company->id,
    ]);

    $readOnlyUser = FilamentHelper::actingAsCompanyUser($company, [
        'view_any_biometric_attendance_device',
        'view_biometric_attendance_device',
    ], true);
    $readOnlyUser->forceFill(['is_active' => true])->saveQuietly();

    Livewire::test(PunchesRelationManager::class, [
        'ownerRecord' => $device,
        'pageClass'   => EditDevice::class,
    ])->assertTableActionHidden('rematchUnmatched');
});

it('hides assignEmployee from device editors who lack update_employee_employee', function () {
    $company = Company::factory()->create();

    $device = Device::create([
        'name'       => 'Auth Device 2',
        'host'       => '10.10.4.11',
        'timezone'   => 'UTC',
        'is_active'  => true,
        'company_id' => $company->id,
    ]);

    $punch = Punch::create([
        'device_id'  => $device->id,
        'zk_user_id' => '00111',
        'punch_time' => '2026-10-02 08:00:00',
        'raw_state'  => 1,
        'raw_type'   => 0,
        'device_uid' => 1,
    ]);

    $deviceEditor = FilamentHelper::actingAsCompanyUser($company, [
        'view_any_biometric_attendance_device',
        'view_biometric_attendance_device',
        'update_biometric_attendance_device',
    ], true);
    $deviceEditor->forceFill(['is_active' => true])->saveQuietly();

    Livewire::test(PunchesRelationManager::class, [
        'ownerRecord' => $device,
        'pageClass'   => EditDevice::class,
    ])
        ->assertTableActionVisible('rematchUnmatched')
        ->assertTableActionHidden('assignEmployee', $punch);
});

it('enforces normalized barcode conflict detection and fires Employee::saved on PunchesRelationManager', function () {
    $company = Company::factory()->create();

    $device = Device::create([
        'name'       => 'Auth Device 3',
        'host'       => '10.10.4.12',
        'timezone'   => 'UTC',
        'is_active'  => true,
        'company_id' => $company->id,
    ]);

    $existingEmployee = Employee::factory()->create([
        'user_id'    => User::factory(),
        'company_id' => $company->id,
        'barcode'    => '111',
        'is_active'  => true,
    ]);

    $targetEmployee = Employee::factory()->create([
        'user_id'    => User::factory(),
        'company_id' => $company->id,
        'barcode'    => null,
        'is_active'  => true,
    ]);

    $punch = Punch::create([
        'device_id'  => $device->id,
        'zk_user_id' => '00111',
        'punch_time' => '2026-10-02 08:00:00',
        'raw_state'  => 1,
        'raw_type'   => 0,
        'device_uid' => 1,
    ]);

    // Authorized user hits normalized barcode conflict (00111 vs 111)
    $fullEditor = FilamentHelper::actingAsCompanyUser($company, [
        'view_any_biometric_attendance_device',
        'view_biometric_attendance_device',
        'update_biometric_attendance_device',
        'view_any_employee_employee',
        'update_employee_employee',
    ], true);
    $fullEditor->forceFill(['is_active' => true])->saveQuietly();

    Livewire::test(PunchesRelationManager::class, [
        'ownerRecord' => $device,
        'pageClass'   => EditDevice::class,
    ])
        ->assertTableActionVisible('rematchUnmatched')
        ->callTableAction('assignEmployee', $punch, [
            'employee_id' => $targetEmployee->id,
        ]);

    expect($targetEmployee->fresh()->barcode)->toBeNull()
        ->and($punch->fresh()->employee_id)->toBeNull();

    // Remove conflict and verify successful assignment fires Employee::saved and links punch
    $existingEmployee->update(['barcode' => '999']);

    Queue::fake();
    $savedFired = false;
    Event::listen('eloquent.saved: '.Employee::class, function (Employee $saved) use ($targetEmployee, &$savedFired): void {
        if ($saved->id === $targetEmployee->id) {
            $savedFired = true;
        }
    });

    Livewire::test(PunchesRelationManager::class, [
        'ownerRecord' => $device,
        'pageClass'   => EditDevice::class,
    ])->callTableAction('assignEmployee', $punch, [
        'employee_id' => $targetEmployee->id,
    ]);

    expect($savedFired)->toBeTrue()
        ->and($targetEmployee->fresh()->barcode)->toBe('00111')
        ->and($punch->fresh()->employee_id)->toBe($targetEmployee->id);
});

it('encrypts comm_key, casts PunchDirectionMode enum, and re-routes corrected punches in attendance', function () {
    $company = Company::factory()->create();

    $device = Device::create([
        'name'       => 'Encrypted Device',
        'host'       => '10.10.5.10',
        'timezone'   => 'UTC',
        'comm_key'   => 123456,
        'is_active'  => true,
        'company_id' => $company->id,
    ]);

    $rawCommKey = DB::table('biometric_devices')->where('id', $device->id)->value('comm_key');

    expect($rawCommKey)->not->toBe('123456')
        ->and($device->toArray())->not->toHaveKey('comm_key')
        ->and((int) $device->fresh()->comm_key)->toBe(123456)
        ->and($device->fresh()->punch_direction_mode)->toBe(PunchDirectionMode::AutoToggle);

    Queue::fake();

    $employee1 = Employee::factory()->create([
        'user_id'    => User::factory(),
        'company_id' => $company->id,
        'is_active'  => true,
    ]);

    $employee2 = Employee::factory()->create([
        'user_id'    => User::factory(),
        'company_id' => $company->id,
        'is_active'  => true,
    ]);

    $punch = Punch::create([
        'device_id'   => $device->id,
        'zk_user_id'  => '500',
        'employee_id' => $employee1->id,
        'punch_time'  => '2026-10-03 08:00:00',
        'raw_state'   => 1,
        'raw_type'    => 0,
        'device_uid'  => 1,
    ]);

    $pipeline = app(SyncPipeline::class);
    $pipeline->pushPendingPunches($device);
    $pipeline->pairAttendance();

    expect($punch->fresh()->pushed_at)->not->toBeNull()
        ->and(Attendance::where('employee_id', $employee1->id)->count())->toBe(1);

    // Reassign punch to employee2 -> pushed_at must reset automatically
    $punch->update(['employee_id' => $employee2->id]);
    expect($punch->fresh()->pushed_at)->toBeNull();

    $pipeline->pushPendingPunches($device);
    $pipeline->pairAttendance();

    $event = AttendanceEvent::where('source', "biometric-attendance:{$device->id}")
        ->where('source_ref', "punch:{$punch->id}")
        ->first();

    expect($punch->fresh()->pushed_at)->not->toBeNull()
        ->and($event)->not->toBeNull()
        ->and($event->employee_id)->toBe($employee2->id)
        ->and(Attendance::where('employee_id', $employee1->id)->count())->toBe(0)
        ->and(Attendance::where('employee_id', $employee2->id)->count())->toBe(1);
});

it('preserves encrypted comm_key when editing device without changing comm_key and updates it to 0 when explicitly set', function () {
    $company = Company::factory()->create();

    $user = FilamentHelper::actingAsCompanyUser($company, [
        'view_any_biometric_attendance_device',
        'view_biometric_attendance_device',
        'update_biometric_attendance_device',
    ], true);
    $user->forceFill(['is_active' => true])->saveQuietly();

    $device = Device::create([
        'name'       => 'Original Gate',
        'host'       => '10.10.6.10',
        'timezone'   => 'UTC',
        'comm_key'   => 123456,
        'is_active'  => true,
        'company_id' => $company->id,
    ]);

    Livewire::test(EditDevice::class, ['record' => $device->getRouteKey()])
        ->fillForm(['name' => 'Renamed Gate'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($device->fresh()->name)->toBe('Renamed Gate')
        ->and((int) $device->fresh()->comm_key)->toBe(123456);

    Livewire::test(EditDevice::class, ['record' => $device->getRouteKey()])
        ->fillForm(['comm_key' => 0])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((int) $device->fresh()->comm_key)->toBe(0);
});
