<?php

return [
    'access_logs_days' => (int) env('RETENTION_ACCESS_LOGS_DAYS', 90),
    'web_analytics_events_days' => (int) env('RETENTION_WEB_ANALYTICS_EVENTS_DAYS', 365),
    'action_logs_days' => (int) env('RETENTION_ACTION_LOGS_DAYS', 365),
    'admin_action_logs_days' => (int) env('RETENTION_ADMIN_ACTION_LOGS_DAYS', 365),
    'ip_geolocations_days' => (int) env('RETENTION_IP_GEOLOCATIONS_DAYS', 90),
    'failed_jobs_hours' => (int) env('RETENTION_FAILED_JOBS_HOURS', 720),
];
