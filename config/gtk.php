<?php

return [
    'application_id' => env('APP_ID', 'com.venusian.gtk'),
    'unique_primary_instance' => false,
    'loop_drain_limit' => 64,
    'video' => [
        'driver' => 'gtk',
        'position_interval' => 100,
    ],
];
