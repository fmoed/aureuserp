<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

use Webkul\BiometricAttendance\Scheduling\SyncSchedule;

it('maps sync cadences to cron expressions with an hourly default', function () {
    expect(SyncSchedule::cronExpression('fifteen-minutes'))->toBe('*/15 * * * *')
        ->and(SyncSchedule::cronExpression('thirty-minutes'))->toBe('*/30 * * * *')
        ->and(SyncSchedule::cronExpression('hourly'))->toBe('0 * * * *')
        ->and(SyncSchedule::cronExpression('daily'))->toBe('0 0 * * *')
        ->and(SyncSchedule::cronExpression('unknown-value'))->toBe('0 * * * *')
        ->and(SyncSchedule::cronExpression())->toBe('0 * * * *')
        ->and(SyncSchedule::options())->toBe(['fifteen-minutes', 'thirty-minutes', 'hourly', 'daily']);
});

it('gates each cadence on its own tick moments', function () {
    expect(SyncSchedule::isDue('fifteen-minutes', new DateTime('2026-10-12 09:15:00')))->toBeTrue()
        ->and(SyncSchedule::isDue('fifteen-minutes', new DateTime('2026-10-12 09:07:00')))->toBeFalse()
        ->and(SyncSchedule::isDue('thirty-minutes', new DateTime('2026-10-12 09:30:00')))->toBeTrue()
        ->and(SyncSchedule::isDue('thirty-minutes', new DateTime('2026-10-12 09:15:00')))->toBeFalse()
        ->and(SyncSchedule::isDue('hourly', new DateTime('2026-10-12 09:00:00')))->toBeTrue()
        ->and(SyncSchedule::isDue('hourly', new DateTime('2026-10-12 09:30:00')))->toBeFalse()
        ->and(SyncSchedule::isDue('daily', new DateTime('2026-10-12 00:00:00')))->toBeTrue()
        ->and(SyncSchedule::isDue('daily', new DateTime('2026-10-12 09:00:00')))->toBeFalse();
});
