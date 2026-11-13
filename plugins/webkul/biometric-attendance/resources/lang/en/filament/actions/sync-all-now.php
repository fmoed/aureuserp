<?php

return [
    'label' => 'Sync all now',

    'notification' => [
        'title'        => 'Sync completed',
        'body'         => 'Devices synced and events processed. The device list shows the new sync times.',
        'failed-title' => 'Sync failed',
        'queued-title' => 'Sync queued',
        'queued-body'  => 'Device sync has been dispatched to the background queue.',
    ],
];
