<?php

return [
    'maxmind_database_path' => env('IP_GEOLOCATION_MAXMIND_DATABASE_PATH'),
    'cache_days' => (int) env('IP_GEOLOCATION_CACHE_DAYS', 30),
];
