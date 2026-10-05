<?php

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
