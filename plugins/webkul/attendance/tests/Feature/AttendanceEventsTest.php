<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webkul\Attendance\Events\EventGateway;
use Webkul\Attendance\Filament\Exports\AttendanceEventExporter;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource\Pages\ListAttendanceEvents;
use Webkul\Attendance\Filament\Resources\AttendanceEventResource\Tables\AttendanceEventsTable;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Models\AttendanceEvent;
use Webkul\Attendance\Policies\AttendanceEventPolicy;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\NavigationGroup;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('attendance');

    WriterRegistry::flush();
    WriterRegistry::register('biometric-attendance');
    WriterRegistry::register('remote');
});

it('registers the attendance event resource as read-only with slug, group and shield config', function () {
    expect(AttendanceEventResource::getSlug())->toBe('attendance/attendance-events')
        ->and(AttendanceEventResource::getNavigationGroup())->toBe(NavigationGroup::Attendance)
        ->and(AttendanceEventResource::getModelLabel())->toBe('Attendance Event')
        ->and(array_keys(AttendanceEventResource::getPages()))->toBe(['index'])
        ->and(config('filament-shield.resources.manage'))->toHaveKey(AttendanceEventResource::class);
});

it('maps the attendance event policy', function () {
    expect(Gate::getPolicyFor(AttendanceEvent::class))
        ->toBeInstanceOf(AttendanceEventPolicy::class);
});

it('authorizes the shield-generated permission names', function () {
    $user = FilamentHelper::actingAs([
        'view_any_attendance_attendance::event',
        'view_attendance_attendance::event',
    ]);

    $event = EventGateway::push(
        Employee::factory()->create(['user_id' => User::factory()])->id,
        '2026-09-24 08:00:00', 0, 'biometric-attendance:3', 'evt-auth'
    );

    expect($user->can('viewAny', AttendanceEvent::class))->toBeTrue()
        ->and($user->can('view', $event))->toBeTrue();
});

it('exposes the expected event exporter columns', function () {
    $columns = collect(AttendanceEventExporter::getColumns())->map->getName()->all();

    expect($columns)->toBe([
        'employee.name',
        'punched_at',
        'direction',
        'source',
        'attendance.check_in',
        'pushed',
    ]);
});

it('maps persisted events to export values on the employee wall clock', function () {
    $riyadhEmployee = Employee::factory()->create([
        'user_id'   => User::factory(),
        'time_zone' => 'Asia/Riyadh',
    ]);

    $row = Attendance::create([
        'employee_id'  => $riyadhEmployee->id,
        'work_date'    => '2026-09-24',
        'check_in'     => '2026-09-24 05:00:00',
        'check_out'    => '2026-09-24 13:00:00',
        'source'       => 'biometric-attendance:3',
        'source_label' => 'Main Gate',
    ]);

    $paired = EventGateway::push(
        $riyadhEmployee->id, '2026-09-24 05:00:00',
        AttendanceEvent::DIRECTION_IN, 'biometric-attendance:3', 'evt-1', 'Main Gate'
    );
    $paired->update(['attendance_id' => $row->id]);

    $pendingOut = EventGateway::push(
        $riyadhEmployee->id, '2026-09-24 09:00:00',
        AttendanceEvent::DIRECTION_OUT, 'biometric-attendance:3', 'evt-2', 'Main Gate'
    );

    $pendingUnknown = EventGateway::push(
        $riyadhEmployee->id, '2026-09-24 10:00:00',
        AttendanceEvent::DIRECTION_UNKNOWN, 'biometric-attendance:3', 'evt-3', 'Main Gate'
    );

    $columns = AttendanceEventExporter::getColumns();
    $columnMap = collect($columns)->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

    $export = new Export(['exporter' => AttendanceEventExporter::class]);
    $exporter = $export->getExporter($columnMap, []);

    expect($exporter($paired->refresh()))->toBe([
        $riyadhEmployee->name,
        '2026-09-24 08:00:00',
        'In',
        'Main Gate',
        '2026-09-24 08:00:00',
        'Paired',
    ])->and($exporter($pendingOut->refresh()))->toBe([
        $riyadhEmployee->name,
        '2026-09-24 12:00:00',
        'Out',
        'Main Gate',
        '',
        'Pending',
    ])->and($exporter($pendingUnknown->refresh()))->toBe([
        $riyadhEmployee->name,
        '2026-09-24 13:00:00',
        'Unknown',
        'Main Gate',
        '',
        'Pending',
    ]);
});

it('links the event employee name to the employee view page', function () {
    $employee = Employee::factory()->create();
    $event = EventGateway::push(
        $employee->id,
        '2026-09-24 08:00:00', 0, 'biometric-attendance:3', 'evt-link'
    );

    FilamentHelper::actingAs([
        'view_any_attendance_attendance::event',
        'view_employee_employee',
    ]);

    $column = collect(Livewire::test(ListAttendanceEvents::class)->instance()->getTable()->getColumns())
        ->first(fn (object $column): bool => $column->getName() === 'employee.name');

    expect($column)->not->toBeNull()
        ->and($column->record($event)->getUrl())->toBe(EmployeeResource::getUrl('view', ['record' => $employee]));
});

it('hides the event employee link without the employee view permission', function () {
    $employee = Employee::factory()->create();
    $event = EventGateway::push(
        $employee->id,
        '2026-09-24 08:00:00', 0, 'biometric-attendance:3', 'evt-link-denied'
    );

    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    $column = collect(Livewire::test(ListAttendanceEvents::class)->instance()->getTable()->getColumns())
        ->first(fn (object $column): bool => $column->getName() === 'employee.name');

    expect($column?->record($event)->getUrl())->toBeNull();
});

it('renders the events list page', function () {
    FilamentHelper::actingAs([
        'view_any_attendance_attendance::event',
        'view_attendance_attendance::event',
    ]);

    Livewire::test(ListAttendanceEvents::class)->assertOk();
});

it('filters events by employee', function () {
    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    $employeeA = Employee::factory()->create(['user_id' => User::factory()]);
    $employeeB = Employee::factory()->create(['user_id' => User::factory()]);

    $first = EventGateway::push($employeeA->id, '2026-09-10 08:00:00', 0, 'biometric-attendance:3', 'evt-a1');
    $second = EventGateway::push($employeeA->id, '2026-09-10 09:00:00', 0, 'biometric-attendance:3', 'evt-a2');
    $other = EventGateway::push($employeeB->id, '2026-09-10 08:00:00', 0, 'biometric-attendance:3', 'evt-b1');

    Livewire::test(ListAttendanceEvents::class)
        ->filterTable('employee_id', $employeeA->id)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters events by pairing linkage', function () {
    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    $employee = Employee::factory()->create(['user_id' => User::factory()]);

    // Raw punches are paired automatically via attendance:process-events;
    // we establish the link manually here to control the test scenario directly.
    $paired = EventGateway::push($employee->id, '2026-09-10 08:00:00', 0, 'biometric-attendance:3', 'evt-p1');
    $unpaired = EventGateway::push($employee->id, '2026-09-10 09:00:00', 0, 'biometric-attendance:3', 'evt-p2');

    $row = Attendance::create([
        'employee_id' => $employee->id,
        'work_date'   => '2026-09-10',
        'check_in'    => '2026-09-10 08:00:00',
        'check_out'   => '2026-09-10 17:00:00',
        'source'      => 'biometric-attendance',
        'company_id'  => $employee->company_id,
    ]);

    $paired->update(['attendance_id' => $row->id]);

    Livewire::test(ListAttendanceEvents::class)
        ->filterTable('linkage', 'unpaired')
        ->assertCanSeeTableRecords([$unpaired])
        ->assertCanNotSeeTableRecords([$paired]);

    Livewire::test(ListAttendanceEvents::class)
        ->filterTable('linkage', 'paired')
        ->assertCanSeeTableRecords([$paired])
        ->assertCanNotSeeTableRecords([$unpaired]);
});

it('filters events by device source', function () {
    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    $employee = Employee::factory()->create(['user_id' => User::factory()]);

    $device = EventGateway::push($employee->id, '2026-09-10 08:00:00', 0, 'biometric-attendance:3', 'evt-1');
    $remote = EventGateway::push($employee->id, '2026-09-10 09:00:00', 0, 'remote', 'evt-2');

    Livewire::test(ListAttendanceEvents::class)
        ->filterTable('source', 'biometric-attendance:3')
        ->assertCanSeeTableRecords([$device])
        ->assertCanNotSeeTableRecords([$remote]);
});

it('filters events by punch date range', function () {
    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    $employee = Employee::factory()->create(['user_id' => User::factory()]);

    $inside = EventGateway::push($employee->id, '2026-09-10 08:00:00', 0, 'biometric-attendance:3', 'evt-1');
    $outside = EventGateway::push($employee->id, '2026-09-20 08:00:00', 0, 'biometric-attendance:3', 'evt-2');

    Livewire::test(ListAttendanceEvents::class)
        ->filterTable('punched_at', ['punched_from' => '2026-09-01', 'punched_to' => '2026-09-15'])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('rejects punch date ranges wider than ninety-three days', function () {
    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    $employee = Employee::factory()->create(['user_id' => User::factory()]);

    $inside = EventGateway::push($employee->id, '2026-09-10 08:00:00', 0, 'biometric-attendance:3', 'evt-wide-1');
    $alsoInRange = EventGateway::push($employee->id, '2026-05-01 08:00:00', 0, 'biometric-attendance:3', 'evt-wide-2');

    Livewire::test(ListAttendanceEvents::class)
        ->filterTable('punched_at', ['punched_from' => '2026-01-01', 'punched_to' => '2026-12-31'])
        ->assertHasErrors([
            'tableFilters.punched_at.punched_to' => 'The range must not exceed 93 days.',
        ])
        ->assertCanSeeTableRecords([$inside, $alsoInRange])
        ->filterTable('punched_at', ['punched_from' => '2026-05-01', 'punched_to' => '2026-07-31'])
        ->assertHasNoErrors()
        ->assertCanSeeTableRecords([$alsoInRange])
        ->assertCanNotSeeTableRecords([$inside]);
});

it('applies a punch date range of exactly ninety-three days', function () {
    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    $employee = Employee::factory()->create(['user_id' => User::factory()]);

    $inside = EventGateway::push($employee->id, '2026-09-10 08:00:00', 0, 'biometric-attendance:3', 'evt-edge-in');
    $outside = EventGateway::push($employee->id, '2026-01-05 08:00:00', 0, 'biometric-attendance:3', 'evt-edge-out');

    Livewire::test(ListAttendanceEvents::class)
        ->filterTable('punched_at', ['punched_from' => '2026-09-10', 'punched_to' => '2026-12-12'])
        ->assertHasNoErrors()
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('paginates the events list at twenty-five rows by default', function () {
    FilamentHelper::actingAs(['view_any_attendance_attendance::event']);

    for ($i = 1; $i <= 30; $i++) {
        $employee = Employee::factory()->create([
            'name'    => sprintf('Page Emp %02d', $i),
            'user_id' => User::factory(),
        ]);

        EventGateway::push(
            $employee->id,
            Carbon::parse('2026-09-10 08:00:00')->addMinutes($i)->format('Y-m-d H:i:s'),
            0, 'biometric-attendance:3', "page-{$i}"
        );
    }

    $events = AttendanceEvent::query()->orderBy('punched_at', 'desc')->get();

    Livewire::test(ListAttendanceEvents::class)
        ->assertCanSeeTableRecords($events->take(25)->all())
        ->assertCanNotSeeTableRecords($events->skip(25)->all());
});

it('caps event exports at ten thousand rows', function () {
    expect(AttendanceEventsTable::EXPORT_MAX_ROWS)->toBe(10000);
});

it('hides events owned by another company', function () {
    $companyA = CompanyHelper::company();
    $companyB = CompanyHelper::company();

    $own = EventGateway::push(
        Employee::factory()->create(['user_id' => User::factory()])->id,
        '2026-09-24 08:00:00', 0, 'biometric-attendance:3', 'evt-own'
    );
    $own->update(['company_id' => $companyA->id]);

    $other = EventGateway::push(
        Employee::factory()->create(['user_id' => User::factory()])->id,
        '2026-09-24 08:00:00', 0, 'biometric-attendance:3', 'evt-other'
    );
    $other->update(['company_id' => $companyB->id]);

    CompanyHelper::actingAsCompanyUser($companyA);

    $visible = AttendanceEvent::query()->pluck('id');

    expect($visible)->toContain($own->id)
        ->not->toContain($other->id);
});

it('scopes the attendance events export query by employee and date range', function () {
    $empA = Employee::factory()->create(['user_id' => User::factory()]);
    $empB = Employee::factory()->create(['user_id' => User::factory()]);

    $evt1 = AttendanceEvent::create([
        'employee_id' => $empA->id,
        'punched_at'  => '2026-09-01 08:00:00',
        'direction'   => AttendanceEvent::DIRECTION_IN,
        'source'      => 'manual',
        'source_ref'  => 'ref-1',
    ]);

    $evt2 = AttendanceEvent::create([
        'employee_id' => $empA->id,
        'punched_at'  => '2026-09-15 08:00:00',
        'direction'   => AttendanceEvent::DIRECTION_IN,
        'source'      => 'manual',
        'source_ref'  => 'ref-2',
    ]);

    $evt3 = AttendanceEvent::create([
        'employee_id' => $empB->id,
        'punched_at'  => '2026-09-15 08:00:00',
        'direction'   => AttendanceEvent::DIRECTION_IN,
        'source'      => 'manual',
        'source_ref'  => 'ref-3',
    ]);

    $query = AttendanceEventExporter::scopeQuery(AttendanceEvent::query(), [
        'employee_id' => $empA->id,
        'date_from'   => '2026-09-10',
        'date_to'     => '2026-09-20',
    ]);

    expect($query->pluck('id')->all())->toBe([$evt2->id]);
});

it('names attendance events export files by period and scope', function () {
    $employee = Employee::factory()->create(['user_id' => User::factory(), 'name' => 'Ahmed Ali']);

    $export = new Export(['exporter' => AttendanceEventExporter::class]);
    $exporter = $export->getExporter([], [
        'date_from'   => '2026-09-01',
        'date_to'     => '2026-09-30',
        'employee_id' => $employee->id,
    ]);

    expect($exporter->getFileName(new Export))->toBe('attendance-events_2026-09-01_to_2026-09-30_emp-ahmed-ali');
});
