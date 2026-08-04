<?php

return [
    'audit_queue' => [
        'stale_pending_minutes' => (int) env('HEALTH_STALE_PENDING_MINUTES', 10),
        'stale_running_minutes' => (int) env('HEALTH_STALE_RUNNING_MINUTES', 15),
        'recent_failed_minutes' => (int) env('HEALTH_RECENT_FAILED_MINUTES', 60),
    ],
];
