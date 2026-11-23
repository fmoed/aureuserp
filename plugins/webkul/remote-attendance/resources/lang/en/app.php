<?php

return [
    'source'              => 'Remote Check-in',
    'daily-log-subject'   => 'Daily Accomplishment (:date)',
    'daily-log-task-ref'  => 'Task: :task',
    'daily-log-hours-ref' => ':hours h',

    'exceptions' => [
        'not-eligible'         => 'Your employee profile is not configured for remote check-in.',
        'period-locked'        => 'Attendance for this date is locked for payroll.',
        'already-checked-in'   => 'You have already checked in for today.',
        'no-open-session'      => 'There is no open remote check-in session to check out.',
        'invalid-checkout'     => 'Check-out must be within :hours hours of check-in.',
        'check-in-required'    => 'Please check in before logging today\'s accomplished tasks.',
        'description-required' => 'Please describe the tasks accomplished today.',
    ],

    'notification' => [
        'title'  => 'Remote Check-in Reminder',
        'body'   => 'Good morning! Please record your remote check-in and log your tasks for :date.',
        'action' => 'Check In Now',
    ],

    'mail' => [
        'subject'       => 'Daily Remote Check-in Reminder (:date)',
        'greeting'      => 'Hello :name,',
        'intro'         => 'It is time to record your remote check-in for :date.',
        'task-reminder' => 'Remember to log the tasks you accomplish today from your remote check-in page.',
        'action'        => 'Open Remote Check-in',
        'salutation'    => 'Regards,',
        'subcopy'       => 'If you\'re having trouble clicking the ":action" button, copy and paste the URL below into your web browser:',
    ],
];
