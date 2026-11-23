<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Webkul\Attendance\Enums\MissingCheckoutPolicy;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\Attendance\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Webkul\Attendance\Models\Attendance;
use Webkul\Attendance\Services\AttendanceOverviewService;
use Webkul\Attendance\Settings\AttendanceSettings;
use Webkul\Attendance\Writers\WriterRegistry;
use Webkul\Chatter\Models\Message;
use Webkul\Employee\Enums\WorkLocation as WorkLocationEnum;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Project\Enums\TaskState;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\Task;
use Webkul\Project\Models\TaskStage;
use Webkul\Project\Models\Timesheet;
use Webkul\RemoteAttendance\Filament\Clusters\Settings\Pages\ManageRemoteAttendance as SettingsManageRemoteAttendance;
use Webkul\RemoteAttendance\Filament\Pages\ManageRemoteAttendance;
use Webkul\RemoteAttendance\Filament\Pages\RemoteCheckIn;
use Webkul\RemoteAttendance\Filament\Widgets\RemoteCheckInWidget;
use Webkul\RemoteAttendance\Notifications\RemoteCheckInReminderNotification;
use Webkul\RemoteAttendance\RemoteAttendanceServiceProvider;
use Webkul\RemoteAttendance\Services\RemoteCheckInService;
use Webkul\RemoteAttendance\Settings\RemoteAttendanceSettings;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State as LeaveState;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('remote-attendance');
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    TestBootstrapHelper::ensurePluginInstalled('projects');
    TestBootstrapHelper::ensurePluginInstalled('timesheets');

    if (! WriterRegistry::isRegistered('remote')) {
        app()->getProvider(RemoteAttendanceServiceProvider::class)?->packageBooted();
    }
});

afterEach(function () {
    $remoteSettings = app(RemoteAttendanceSettings::class);
    $remoteSettings->reminder_enabled = true;
    $remoteSettings->reminder_time = '09:00';
    $remoteSettings->require_checkout = false;
    $remoteSettings->only_home_location = true;
    $remoteSettings->allowed_location_types = [WorkLocationEnum::Home->value];
    $remoteSettings->allowed_work_location_ids = [];
    $remoteSettings->save();

    $attendanceSettings = app(AttendanceSettings::class);
    $attendanceSettings->attendance_lock_date = null;
    $attendanceSettings->save();

    Carbon::setTestNow();
});

function createRemoteRoster(int $companyId, MissingCheckoutPolicy $missingPolicy = MissingCheckoutPolicy::AutoCreditScheduled): Calendar
{
    $calendar = Calendar::factory()->create([
        'name'                    => 'Remote 9-5 Roster',
        'timezone'                => 'UTC',
        'company_id'              => $companyId,
        'missing_checkout_policy' => $missingPolicy,
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
        CalendarAttendance::create([
            'name'        => "{$day} shift",
            'day_of_week' => $day,
            'day_period'  => 'morning',
            'calendar_id' => $calendar->id,
            'hour_from'   => 9,
            'hour_to'     => 17,
        ]);
    }

    return $calendar;
}

function createRemoteEmployee(Company $company, WorkLocationEnum $locationType = WorkLocationEnum::Home, ?Calendar $calendar = null, array $overrides = []): Employee
{
    $location = WorkLocation::factory()->create([
        'name'          => ucfirst($locationType->value).' Location',
        'location_type' => $locationType,
        'is_active'     => true,
        'company_id'    => $company->id,
    ]);

    $user = User::factory()->create(['default_company_id' => $company->id]);

    return Employee::factory()->create(array_merge([
        'user_id'          => $user->id,
        'work_location_id' => $location->id,
        'calendar_id'      => $calendar?->id,
        'time_zone'        => 'UTC',
        'barcode'          => null,
        'is_active'        => true,
        'company_id'       => $company->id,
    ], $overrides));
}

it('registers the remote writer and exposes remote home employees in the enrolled scope', function () {
    $company = CompanyHelper::company();
    FilamentHelper::actingAsCompanyUser($company);

    $remoteEmp = createRemoteEmployee($company, WorkLocationEnum::Home);
    $officeEmp = createRemoteEmployee($company, WorkLocationEnum::Office);

    $enrolledIds = AttendanceOverviewService::scopeEligibleEmployees(
        Employee::query(),
        AttendanceSettings::SCOPE_ENROLLED,
    )->pluck('id')->all();

    expect(WriterRegistry::isRegistered('remote'))->toBeTrue()
        ->and($enrolledIds)->toContain($remoteEmp->id)
        ->and($enrolledIds)->not->toContain($officeEmp->id);
});

it('records remote check-in without checkout and credits scheduled hours via AutoCreditScheduled', function () {
    $company = CompanyHelper::company();
    FilamentHelper::actingAsCompanyUser($company);

    $calendar = createRemoteRoster($company->id, MissingCheckoutPolicy::AutoCreditScheduled);
    $employee = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);

    Carbon::setTestNow('2026-11-23 09:05:00'); // Monday

    $attendance = app(RemoteCheckInService::class)->checkIn($employee, 'Starting API tasks');

    expect($attendance->source)->toBe('remote')
        ->and($attendance->check_out)->toBeNull()
        ->and($attendance->work_date->toDateString())->toBe('2026-11-23');

    // After shift end, MissingCheckoutPolicy::AutoCreditScheduled credits scheduled hours.
    Carbon::setTestNow('2026-11-23 18:00:00');

    $entry = app(AttendanceOverviewService::class)->daily(
        Employee::whereKey($employee->id)->get(),
        Carbon::parse('2026-11-23'),
        Carbon::parse('2026-11-23'),
    )->first();

    expect($entry)->not->toBeNull()
        ->and($entry['status'])->toBe(AttendanceOverviewService::STATUS_PRESENT)
        ->and($entry['minutes'])->toBe(480)
        ->and($entry['is_estimated'])->toBeTrue();
});

it('enforces eligibility, duplicate check-in prevention, and payroll lock date safeguards', function () {
    $company = CompanyHelper::company();
    FilamentHelper::actingAsCompanyUser($company);

    $calendar = createRemoteRoster($company->id);
    $officeEmp = createRemoteEmployee($company, WorkLocationEnum::Office, $calendar);
    $remoteEmp = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);

    $service = app(RemoteCheckInService::class);

    Carbon::setTestNow('2026-11-23 09:00:00');

    expect(fn () => $service->checkIn($officeEmp))
        ->toThrow(DomainException::class);

    $service->checkIn($remoteEmp);

    expect(fn () => $service->checkIn($remoteEmp))
        ->toThrow(DomainException::class);

    expect(Attendance::where('employee_id', $remoteEmp->id)->whereDate('work_date', '2026-11-23')->count())->toBe(1);

    $attendanceSettings = app(AttendanceSettings::class);
    $attendanceSettings->attendance_lock_date = '2026-11-25';
    $attendanceSettings->save();

    Carbon::setTestNow('2026-11-24 09:00:00');

    expect(fn () => $service->checkIn($remoteEmp))
        ->toThrow(DomainException::class);
});

it('supports optional remote check-out when require_checkout policy is enabled', function () {
    $company = CompanyHelper::company();
    FilamentHelper::actingAsCompanyUser($company);

    $settings = app(RemoteAttendanceSettings::class);
    $settings->require_checkout = true;
    $settings->save();

    $calendar = createRemoteRoster($company->id);
    $employee = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);
    $service = app(RemoteCheckInService::class);

    Carbon::setTestNow('2026-11-23 09:00:00');
    $attendance = $service->checkIn($employee);

    Carbon::setTestNow('2026-11-23 17:30:00');
    $closed = $service->checkOut($employee, 'Finished all tickets');

    expect($closed->id)->toBe($attendance->id)
        ->and($closed->check_out)->not->toBeNull()
        ->and($closed->worked_minutes)->toBe(510);

    // Even after checking out on the same workday, a second remote check-in must be rejected.
    Carbon::setTestNow('2026-11-23 18:00:00');
    expect(fn () => $service->checkIn($employee))
        ->toThrow(DomainException::class);

    expect(Attendance::where('employee_id', $employee->id)->whereDate('work_date', '2026-11-23')->count())->toBe(1);
});

it('supports flexible location types and specific work location selection', function () {
    $company = CompanyHelper::company();
    FilamentHelper::actingAsCompanyUser($company);

    $calendar = createRemoteRoster($company->id);
    $homeEmp = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);
    $otherEmp = createRemoteEmployee($company, WorkLocationEnum::Other, $calendar);
    $officeEmp = createRemoteEmployee($company, WorkLocationEnum::Office, $calendar);

    $service = app(RemoteCheckInService::class);

    // Allow Home + Other location types, plus the specific office employee's location by ID.
    $settings = app(RemoteAttendanceSettings::class);
    $settings->allowed_location_types = [WorkLocationEnum::Home->value, WorkLocationEnum::Other->value];
    $settings->allowed_work_location_ids = [$officeEmp->work_location_id];
    $settings->save();

    $eligibleIds = RemoteCheckInService::applyEligibilityScope(Employee::query())->pluck('id')->all();

    expect($service->isEligible($homeEmp))->toBeTrue()
        ->and($service->isEligible($otherEmp))->toBeTrue()
        ->and($service->isEligible($officeEmp))->toBeTrue()
        ->and($eligibleIds)->toContain($homeEmp->id, $otherEmp->id, $officeEmp->id);

    // Remove specific office ID: only Home + Other stay eligible.
    $settings->allowed_work_location_ids = [];
    $settings->save();

    $eligibleIds = RemoteCheckInService::applyEligibilityScope(Employee::query())->pluck('id')->all();

    expect($service->isEligible($officeEmp->fresh()))->toBeFalse()
        ->and($eligibleIds)->toContain($homeEmp->id, $otherEmp->id)
        ->and($eligibleIds)->not->toContain($officeEmp->id);
});

it('logs daily accomplished tasks both standalone via chatter and integrated with projects and timesheets', function () {
    $company = CompanyHelper::company();
    $actor = FilamentHelper::actingAsCompanyUser($company);

    $calendar = createRemoteRoster($company->id);
    $employee = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);
    $service = app(RemoteCheckInService::class);

    Carbon::setTestNow('2026-11-23 09:00:00');
    $attendance = $service->checkIn($employee);

    // Use case 1: standalone daily accomplishment note without project/timesheet
    $standalone = $service->logDailyWork($employee, [
        'description' => 'Reviewed server logs and updated deployment docs',
    ]);

    expect($standalone['task'])->toBeNull()
        ->and($standalone['timesheet'])->toBeNull();

    // Use case 2: integrated with Project + Task + Timesheet
    $project = Project::factory()->create([
        'name'             => 'ERP Remote Portal',
        'company_id'       => $company->id,
        'user_id'          => $actor->id,
        'allow_timesheets' => true,
        'is_active'        => true,
    ]);

    $stage = TaskStage::create([
        'name'       => 'Todo',
        'project_id' => $project->id,
        'company_id' => $company->id,
        'user_id'    => $actor->id,
        'creator_id' => $actor->id,
        'is_active'  => true,
    ]);

    $task = Task::create([
        'title'           => 'Build check-in API',
        'project_id'      => $project->id,
        'stage_id'        => $stage->id,
        'company_id'      => $company->id,
        'creator_id'      => $actor->id,
        'allocated_hours' => 8,
        'state'           => TaskState::IN_PROGRESS,
        'is_active'       => true,
    ]);

    $integrated = $service->logDailyWork($employee, [
        'project_id'  => $project->id,
        'task_id'     => $task->id,
        'task_state'  => TaskState::DONE->value,
        'unit_amount' => 3.5,
        'description' => 'Implemented and tested remote check-in endpoint',
    ]);

    expect($integrated['task'])->not->toBeNull()
        ->and($integrated['task']->fresh()->state)->toBe(TaskState::DONE)
        ->and((float) $integrated['task']->fresh()->total_hours_spent)->toBe(3.5)
        ->and($integrated['timesheet'])->toBeInstanceOf(Timesheet::class)
        ->and((int) $integrated['timesheet']->project_id)->toBe($project->id)
        ->and((int) $integrated['timesheet']->task_id)->toBe($task->id);

    // Use case 3: multi-item batch logging (mix of standalone and auto-created project task + timesheet)
    $created = $service->logDailyWork($employee, [
        'items' => [
            [
                'description' => 'Reviewed deployment checklist',
            ],
            [
                'project_id'     => $project->id,
                'new_task_title' => 'Write API documentation',
                'task_state'     => TaskState::DONE->value,
                'unit_amount'    => 1.5,
                'description'    => 'Documented endpoints',
            ],
        ],
    ]);

    expect($created['items'])->toHaveCount(2)
        ->and($created['task'])->not->toBeNull()
        ->and($created['task']->title)->toBe('Write API documentation')
        ->and($created['task']->fresh()->state)->toBe(TaskState::DONE)
        ->and((float) $created['task']->fresh()->total_hours_spent)->toBe(1.5);

    $messagesCount = Message::query()
        ->where('messageable_type', Attendance::class)
        ->where('messageable_id', $attendance->id)
        ->where('type', 'comment')
        ->count();

    expect($messagesCount)->toBe(3);
});

it('sends morning 9am reminders idempotently and skips checked-in, leave, and office employees', function () {
    Notification::fake();

    $company = CompanyHelper::company();
    FilamentHelper::actingAsCompanyUser($company);

    $calendar = createRemoteRoster($company->id);

    $needsReminder = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);
    $alreadyCheckedIn = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);
    $onLeave = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);
    $officeEmp = createRemoteEmployee($company, WorkLocationEnum::Office, $calendar);

    Carbon::setTestNow('2026-11-23 09:05:00'); // Monday

    app(RemoteCheckInService::class)->checkIn($alreadyCheckedIn);

    $leaveType = LeaveType::create([
        'name'                  => 'Annual Leave',
        'leave_validation_type' => 'both',
        'company_id'            => $company->id,
    ]);

    Leave::create([
        'employee_id'       => $onLeave->id,
        'holiday_status_id' => $leaveType->id,
        'date_from'         => '2026-11-23 00:00:00',
        'date_to'           => '2026-11-23 23:59:59',
        'request_date_from' => '2026-11-23',
        'request_date_to'   => '2026-11-23',
        'state'             => LeaveState::VALIDATE_TWO,
        'company_id'        => $company->id,
    ]);

    Cache::forget("remote-attendance:reminded:{$needsReminder->id}:2026-11-23");

    $this->artisan('remote-attendance:send-reminders')
        ->expectsOutputToContain('sent: 1')
        ->assertSuccessful();

    Notification::assertSentTo($needsReminder->user, RemoteCheckInReminderNotification::class);
    Notification::assertNotSentTo($alreadyCheckedIn->user, RemoteCheckInReminderNotification::class);
    Notification::assertNotSentTo($onLeave->user, RemoteCheckInReminderNotification::class);
    Notification::assertNotSentTo($officeEmp->user, RemoteCheckInReminderNotification::class);

    // Second run same day must be idempotent (sends 0)
    $this->artisan('remote-attendance:send-reminders')
        ->expectsOutputToContain('sent: 0')
        ->assertSuccessful();

    Notification::assertCount(1);

    // Explicit --force on the same day must STILL respect daily deduplication (sends 0)
    $this->artisan('remote-attendance:send-reminders', ['--force' => true])
        ->expectsOutputToContain('sent: 0')
        ->assertSuccessful();

    Notification::assertCount(1);

    // Outside reminder window on the same day (for an unreminded employee): regular run skips, --force ignores only time-of-day window
    Carbon::setTestNow('2026-11-23 11:30:00'); // Monday outside 09:00..11:00 window
    Cache::forget("remote-attendance:reminded:{$needsReminder->id}:2026-11-23");

    $this->artisan('remote-attendance:send-reminders')
        ->expectsOutputToContain('sent: 0')
        ->assertSuccessful();

    $this->artisan('remote-attendance:send-reminders', ['--force' => true])
        ->expectsOutputToContain('sent: 1')
        ->assertSuccessful();

    $this->artisan('remote-attendance:send-reminders', ['--force' => true])
        ->expectsOutputToContain('sent: 0')
        ->assertSuccessful();

    Notification::assertCount(2);

    $scheduledEvent = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'remote-attendance:send-reminders'));

    expect($scheduledEvent)->not->toBeNull()
        ->and($scheduledEvent->expression)->toBe('*/15 * * * *');
});

it('allows eligible employees to check in and log work through RemoteCheckInWidget and shows it on ListAttendances', function () {
    $company = CompanyHelper::company();
    $user = FilamentHelper::actingAsCompanyUser($company, ['view_any_attendance_attendance']);
    $user->forceFill(['is_active' => true])->saveQuietly();

    $calendar = createRemoteRoster($company->id);
    $location = WorkLocation::factory()->create([
        'name'          => 'Home Office',
        'location_type' => WorkLocationEnum::Home,
        'is_active'     => true,
        'company_id'    => $company->id,
    ]);

    $employee = Employee::factory()->create([
        'user_id'          => $user->id,
        'work_location_id' => $location->id,
        'calendar_id'      => $calendar->id,
        'time_zone'        => 'UTC',
        'is_active'        => true,
        'company_id'       => $company->id,
    ]);

    Carbon::setTestNow('2026-11-23 09:10:00');

    expect(RemoteCheckInWidget::canView())->toBeTrue();

    Livewire::test(RemoteCheckInWidget::class)
        ->assertOk()
        ->assertActionVisible('checkIn')
        ->assertActionHidden('logWork')
        ->callAction('checkIn', ['plan_note' => 'Morning standup'])
        ->assertNotified()
        ->assertDispatched('remote-attendance-updated')
        ->assertActionHidden('checkIn')
        ->assertActionVisible('logWork')
        ->callAction('logWork', [
            'items' => [
                ['description' => 'Completed PR review'],
            ],
        ])
        ->assertNotified()
        ->assertDispatched('remote-attendance-updated')
        ->assertSee('Completed PR review');

    Livewire::test(ListAttendances::class)
        ->assertOk()
        ->assertSee('Completed PR review');

    expect(Attendance::where('employee_id', $employee->id)->where('source', 'remote')->count())->toBe(1)
        ->and(FilamentShield::getPages())->toHaveKey(RemoteCheckIn::class)
        ->and(FilamentShield::getPages())->toHaveKey(SettingsManageRemoteAttendance::class)
        ->and(FilamentShield::getPages())->not->toHaveKey(ManageRemoteAttendance::class);
});

it('persists remote attendance settings and renders database and mail reminder payloads', function () {
    $company = CompanyHelper::company();
    $user = FilamentHelper::actingAsCompanyUser($company, ['page_remote_attendance_manage_remote_attendance']);
    $user->forceFill(['is_active' => true])->saveQuietly();

    $location = WorkLocation::factory()->create([
        'name'          => 'Remote Branch',
        'location_type' => WorkLocationEnum::Other,
        'is_active'     => true,
        'company_id'    => $company->id,
    ]);

    Livewire::test(SettingsManageRemoteAttendance::class)
        ->fillForm([
            'only_home_location'        => true,
            'allowed_location_types'    => [WorkLocationEnum::Home->value, WorkLocationEnum::Other->value],
            'allowed_work_location_ids' => [$location->id],
            'require_checkout'          => true,
            'reminder_enabled'          => true,
            'reminder_time'             => '08:30',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app(RemoteAttendanceSettings::class)->refresh();

    expect(RemoteAttendanceSettings::onlyHomeLocation())->toBeTrue()
        ->and(RemoteAttendanceSettings::allowedLocationTypes())->toBe([WorkLocationEnum::Home->value, WorkLocationEnum::Other->value])
        ->and(RemoteAttendanceSettings::allowedWorkLocationIds())->toBe([$location->id])
        ->and(RemoteAttendanceSettings::requireCheckout())->toBeTrue()
        ->and(RemoteAttendanceSettings::reminderEnabled())->toBeTrue()
        ->and(RemoteAttendanceSettings::reminderTime())->toBe('08:30');

    $notification = new RemoteCheckInReminderNotification('Remote Dev', '2026-11-23');

    expect($notification->via($user))->toContain('database', 'mail')
        ->and($notification->toDatabase($user))->toBeArray()
        ->and($notification->toMail($user)->subject)->toContain('2026-11-23');

    Notification::fake();

    Livewire::test(SettingsManageRemoteAttendance::class)
        ->assertActionExists('sendTestReminder')
        ->assertActionDoesNotExist('runRemindersNow')
        ->callAction('sendTestReminder')
        ->assertNotified();

    Notification::assertSentTo($user, RemoteCheckInReminderNotification::class);

    $userWithoutEmail = FilamentHelper::actingAsCompanyUser($company, ['page_remote_attendance_manage_remote_attendance']);
    $userWithoutEmail->forceFill(['email' => '', 'is_active' => true])->saveQuietly();

    Livewire::test(SettingsManageRemoteAttendance::class)
        ->callAction('sendTestReminder')
        ->assertNotified();

    Notification::assertNothingSentTo($userWithoutEmail);
});

it('lets a non-admin remote employee without attendance permissions check in and only see their own rows', function () {
    $company = CompanyHelper::company();

    // Non-admin user with zero explicit permissions (even if resource_permission defaulted to GLOBAL)
    $remoteUser = FilamentHelper::actingAsCompanyUser($company, []);
    $remoteUser->forceFill([
        'is_active'           => true,
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    $calendar = createRemoteRoster($company->id);
    $homeLocation = WorkLocation::factory()->create([
        'name'          => 'Home',
        'location_type' => WorkLocationEnum::Home,
        'is_active'     => true,
        'company_id'    => $company->id,
    ]);

    $ownEmployee = Employee::factory()->create([
        'user_id'          => $remoteUser->id,
        'work_location_id' => $homeLocation->id,
        'calendar_id'      => $calendar->id,
        'time_zone'        => 'UTC',
        'is_active'        => true,
        'company_id'       => $company->id,
    ]);

    $otherEmployee = createRemoteEmployee($company, WorkLocationEnum::Home, $calendar);

    $ownRow = Attendance::create([
        'employee_id' => $ownEmployee->id,
        'work_date'   => '2026-11-22',
        'check_in'    => '2026-11-22 09:00:00',
        'source'      => 'remote',
        'company_id'  => $company->id,
    ]);

    $foreignRow = Attendance::create([
        'employee_id' => $otherEmployee->id,
        'work_date'   => '2026-11-22',
        'check_in'    => '2026-11-22 09:00:00',
        'source'      => 'remote',
        'company_id'  => $company->id,
    ]);

    expect(AttendanceResource::canViewAny())->toBeTrue()
        ->and(RemoteCheckInWidget::canView())->toBeTrue()
        ->and(RemoteCheckIn::canAccess())->toBeTrue();

    Livewire::test(ListAttendances::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ownRow])
        ->assertCanNotSeeTableRecords([$foreignRow]);

    Livewire::test(RemoteCheckIn::class)
        ->assertOk();
});

it('keeps ListAttendances forbidden for non-admin office employees without attendance permissions', function () {
    $company = CompanyHelper::company();
    $officeUser = FilamentHelper::actingAsCompanyUser($company, []);
    $officeUser->forceFill(['is_active' => true])->saveQuietly();

    $calendar = createRemoteRoster($company->id);
    $officeLocation = WorkLocation::factory()->create([
        'name'          => 'Main Office',
        'location_type' => WorkLocationEnum::Office,
        'is_active'     => true,
        'company_id'    => $company->id,
    ]);

    Employee::factory()->create([
        'user_id'          => $officeUser->id,
        'work_location_id' => $officeLocation->id,
        'calendar_id'      => $calendar->id,
        'time_zone'        => 'UTC',
        'is_active'        => true,
        'company_id'       => $company->id,
    ]);

    expect(AttendanceResource::canViewAny())->toBeFalse()
        ->and(RemoteCheckInWidget::canView())->toBeFalse()
        ->and(RemoteCheckIn::canAccess())->toBeFalse();

    Livewire::test(ListAttendances::class)->assertForbidden();
    Livewire::test(RemoteCheckIn::class)->assertForbidden();
});

it('renders reminder emails with company branding, public logo URL, missing-file fallback, and dark-mode safe button', function () {
    Storage::fake('public');
    Storage::disk('public')->put(
        'company-logos/prodigy.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
    );

    $company = CompanyHelper::company([
        'name'  => 'Prodigy Systems',
        'email' => 'hr@prodigy-sys.com',
    ]);
    $company->partner()->update(['avatar' => 'company-logos/prodigy.png']);
    $company->refresh();

    $user = FilamentHelper::actingAsCompanyUser($company);

    $notification = new RemoteCheckInReminderNotification('Remote Dev', '2026-11-23', 'https://erp.test/attendance');
    $mail = $notification->toMail($user);
    $html = (string) $mail->render();

    expect($mail->from)->toBe([config('mail.from.address'), 'Prodigy Systems'])
        ->and($mail->replyTo)->toBe([['hr@prodigy-sys.com', 'Prodigy Systems']])
        ->and($mail->callbacks)->toBeEmpty()
        ->and($html)->toContain('Prodigy Systems')
        ->and($html)->toContain('/storage/company-logos/prodigy.png')
        ->and($html)->not->toContain('cid:')
        ->and($html)->toContain('padding: 12px 24px')
        ->and($html)->not->toContain('border-left: 18px solid')
        ->and($html)->not->toContain('<pre>');

    Storage::fake('public', ['url' => 'https://erp.prodigy-sys.com//storage']);
    Storage::disk('public')->put(
        'company-logos/prodigy.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
    );

    $prodHtml = (string) (new RemoteCheckInReminderNotification('Remote Dev', '2026-11-23', 'https://erp.prodigy-sys.com/attendance'))
        ->toMail($user)
        ->render();

    expect($prodHtml)->toContain('src="https://erp.prodigy-sys.com/storage/company-logos/prodigy.png"')
        ->and($prodHtml)->not->toContain('//storage/');

    $company->partner()->update(['avatar' => 'company-logos/missing.png']);
    $fallbackHtml = (string) (new RemoteCheckInReminderNotification('Remote Dev', '2026-11-23', 'https://erp.test/attendance'))
        ->toMail($user)
        ->render();

    expect($fallbackHtml)->toContain('Prodigy Systems')
        ->and($fallbackHtml)->not->toContain('<img');

    $className = RemoteCheckInReminderNotification::class;
    $legacyPayload = sprintf(
        'O:%d:"%s":3:{s:12:"employeeName";s:10:"Remote Dev";s:8:"workDate";s:10:"2026-11-23";s:9:"actionUrl";N;}',
        strlen($className),
        $className,
    );

    /** @var RemoteCheckInReminderNotification $legacyNotification */
    $legacyNotification = unserialize($legacyPayload);
    $legacyMail = $legacyNotification->toMail($user);

    expect((string) $legacyMail->render())->toContain('Prodigy Systems');
});
