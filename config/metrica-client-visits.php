<?php

declare(strict_types=1);

return [
    /* OAuth-токен пользователя с правом metrika:read. OAuth-flow пакет не выполняет. */
    'token' => env('YANDEX_METRIKA_TOKEN'),
    'counter_id' => env('YANDEX_METRIKA_COUNTER_ID'),

    'queue' => env('METRICA_CLIENT_VISITS_QUEUE', 'default'),
    'cache_store' => env('METRICA_CLIENT_VISITS_CACHE_STORE', env('CACHE_STORE', 'database')),

    'default_attribution' => 'last',
    'default_lookback_days' => 30,
    'default_time_tolerance_seconds' => 120,
    'default_goal_id' => env('METRICA_CLIENT_VISITS_GOAL_ID'),

    /* Все timestamps входных событий — UTC. dateTime визита приходит в TZ счётчика. */
    'counter_timezone' => env('METRICA_CLIENT_VISITS_COUNTER_TIMEZONE', 'Europe/Moscow'),
    'goal_timezone' => env('METRICA_CLIENT_VISITS_GOAL_TIMEZONE', 'Europe/Moscow'),

    'max_events_per_batch' => 1_000,
    'max_parallel_exports_per_counter' => 1,
    'max_days_per_export' => 365,
    'polling_delays' => [15, 30, 60, 120],
    'lock_seconds' => 3_600,
    'http_timeout_seconds' => 120,
];
