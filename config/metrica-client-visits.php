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
    /* last сохраняет прежний выбор: самый поздний подходящий визит. first — самый ранний. */
    'default_selection_strategy' => env('METRICA_CLIENT_VISITS_SELECTION_STRATEGY', 'last'),

    /* Все timestamps входных событий — UTC. dateTime визита приходит в TZ счётчика. */
    'counter_timezone' => env('METRICA_CLIENT_VISITS_COUNTER_TIMEZONE', 'Europe/Moscow'),
    'goal_timezone' => env('METRICA_CLIENT_VISITS_GOAL_TIMEZONE', 'Europe/Moscow'),

    'max_events_per_batch' => 1_000,
    'max_parallel_exports_per_counter' => 1,
    'api_requests_per_minute_per_counter' => 30,
    'max_days_per_export' => 365,
    'polling_delays' => [15, 30, 60, 120],
    'lock_seconds' => 86_400,

    /* Должно соблюдаться: HTTP timeout < job timeout < queue retry_after. */
    'http_connect_timeout_seconds' => 10,
    'http_timeout_seconds' => 90,
    'job_timeout_seconds' => 110,
    'queue_retry_after_seconds' => 130,
    'job_backoff_seconds' => [15, 30, 60, 120, 300],
    'job_retry_until_seconds' => 21_600,
];
