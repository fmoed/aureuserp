<?php

return [
    'label'            => 'Rebuild attendance',
    'running-label'    => 'Rebuilding attendance...',
    'heading'          => 'Rebuild attendance records from raw punches',
    'description'      => 'Use this after changing shift rules or moving employees to a new shift. It re-pairs raw biometric events for the selected period using the current shift configuration without touching manual records or locked periods.',
    'date-from'        => 'From date',
    'date-to'          => 'To date',
    'calendar'         => 'Shift',
    'department'       => 'Department',
    'employee'         => 'Employee',
    'all-calendars'    => 'All shifts',
    'all-departments'  => 'All departments',
    'all-employees'    => 'All employees',
    'queued-title'     => 'Attendance rebuild queued',
    'queued-body'      => 'Processing in the background. You will be notified once it finishes.',
    'already-running'  => 'An attendance rebuild is already running. Please wait until it completes.',
    'completed-title'  => 'Attendance rebuild completed',
    'completed'        => 'Rebuilt :rows attendance rows for :employees employees.',
];
