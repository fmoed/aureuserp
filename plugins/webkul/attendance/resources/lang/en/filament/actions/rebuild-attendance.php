<?php

return [
    'label'            => 'Rebuild attendance',
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
    'completed'        => 'Rebuilt :rows attendance rows for :employees employees.',
];
