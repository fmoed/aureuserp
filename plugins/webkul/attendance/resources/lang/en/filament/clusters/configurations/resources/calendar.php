<?php

return [
    'title'      => 'Work Schedule (Shift)',
    'navigation' => [
        'title' => 'Work Schedules',
    ],

    'actions' => [
        'assign-employees' => [
            'label'           => 'Assign employees to this shift',
            'department'      => 'Department (optional)',
            'department-hint' => 'Applies the shift to every employee in this department.',
            'employees'       => 'Employees (optional)',
            'employees-hint'  => 'Hand-picked employees override the department and job filters.',
            'job'             => 'Job position (optional)',
            'job-hint'        => 'Restricts the assignment to employees holding this job position.',
            'preview-label'   => 'Employees to update',
            'preview-count'   => ':count employees match the current selection.',
            'select-required' => 'Choose a department, a job position, or at least one employee.',
            'assigned'        => ':count employees assigned to this shift.',
        ],
    ],

    'table' => [
        'columns' => [
            'employees-count'         => 'Assigned employees',
            'employees-count-tooltip' => 'Default shift: :direct assigned directly, :inherited included automatically (no shift set).',
        ],
    ],
];
