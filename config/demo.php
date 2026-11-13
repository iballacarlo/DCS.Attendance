<?php

return [
    'enabled' => (bool) env('DEMO_MODE', false) && env('APP_ENV', 'production') === 'local',
    'allow_any_time' => (bool) env('DEMO_ALLOW_ANY_TIME', false)
        && (bool) env('DEMO_MODE', false)
        && env('APP_ENV', 'production') === 'local',
];
