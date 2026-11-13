<?php

$demoMode = filter_var(env('DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN);
$remoteDemoEnabled = filter_var(env('DEMO_REMOTE_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
$isLocal = env('APP_ENV', 'production') === 'local';

return [
    // Remote demo mode is opt-in and remains disabled by default, even when
    // DEMO_MODE is accidentally copied to a production environment.
    'enabled' => $demoMode && ($isLocal || $remoteDemoEnabled),
    'allow_any_time' => filter_var(env('DEMO_ALLOW_ANY_TIME', false), FILTER_VALIDATE_BOOLEAN)
        && $demoMode
        && ($isLocal || $remoteDemoEnabled),
];
