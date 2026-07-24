# Laravel Metrica Client Visits

`phpdmitry/laravel-metrica-client-visits` асинхронно сопоставляет бизнес-события с визитами Яндекс.Метрики по `ClientID` и времени события. Пакет создаёт выгрузку через [Logs API](https://yandex.ru/dev/metrika/doc/api2/logs/intro.html), получает TSV, сохраняет все визиты нужных клиентов и выбирает один лучший визит для каждого события.

Это не SDK Метрики и не инструмент для произвольных отчётов. Пакет работает только с визитами (`source=visits`) и фиксированным набором полей Logs API. Полная выгрузка счётчика в БД не сохраняется: обрабатываются и сохраняются только строки для переданных `ClientID`.

## Возможности

- Один batch для одного или нескольких событий, с единым планом выгрузок.
- Все найденные визиты сохраняются как `VisitCandidate`; лучший — как совместимый `VisitMatch`.
- Проверка цели Метрики, временного окна и выбора первого либо последнего подходящего визита.
- Повторная выгрузка завершённого или failed batch с тем же `externalId`.
- Защита от одновременного запуска идентичных активных batch.
- Очереди Laravel, cache locks для ограничения параллельных экспортов, rate limiting и очистка временных Logs-запросов в Метрике.
- Корректное хранение времени в UTC независимо от `APP_TIMEZONE`.

## Требования

- PHP `^8.2`.
- Laravel 12 или 13 с доступными компонентами database, queue, cache, HTTP и console.
- Счётчик Яндекс.Метрики и OAuth-токен с правом `metrika:read`.
- Работающий queue worker и cache store, поддерживающий locks.

Redis не обязателен. Пакет протестирован с database-очередью и database-cache; можно использовать и другие штатные драйверы Laravel.

## Установка

```bash
composer require phpdmitry/laravel-metrica-client-visits

php artisan vendor:publish --tag=metrica-client-visits-config
php artisan migrate
```

Service provider и миграции пакета подключаются автоматически через Laravel package discovery. Публикация конфига необязательна, но нужна, если настройки должны храниться в проекте.

### Database queue и cache

Если выбраны стандартные database-драйверы Laravel и их таблиц ещё нет, создайте их один раз:

```bash
php artisan make:queue-table
php artisan make:cache-table
php artisan migrate
```

Не смешивайте это с миграциями пакета: первые команды создают инфраструктурные таблицы Laravel, а `php artisan migrate` применяет и их, и таблицы `metrica_visit_*`.

## Учётные данные и конфигурация

Задайте секреты только в окружении, не добавляйте OAuth-токен в репозиторий:

```dotenv
YANDEX_METRIKA_TOKEN=oauth-токен-с-правом-metrika-read
YANDEX_METRIKA_COUNTER_ID=12345678

# Пример для database driver; допустимы и другие драйверы Laravel.
QUEUE_CONNECTION=database
CACHE_STORE=database

# Имя очереди пакета и согласованный retry_after для database queue.
METRICA_CLIENT_VISITS_QUEUE=metrica-client-visits
DB_QUEUE_RETRY_AFTER=130

# Обычно часовой пояс счётчика Метрики. Входные Unix timestamps всегда UTC.
METRICA_CLIENT_VISITS_COUNTER_TIMEZONE=Europe/Moscow
METRICA_CLIENT_VISITS_GOAL_TIMEZONE=Europe/Moscow
```

OAuth-flow пакет не выполняет: `client_id` и `client_secret` ему не нужны. Токен отправляется в Logs API в заголовке `Authorization: OAuth …`.

После публикации настройте `config/metrica-client-visits.php`. Доступные параметры и значения по умолчанию:

| Параметр | По умолчанию | Назначение |
| --- | --- | --- |
| `token`, `counter_id` | `YANDEX_METRIKA_TOKEN`, `YANDEX_METRIKA_COUNTER_ID` | OAuth-токен и счётчик, если не заданы в запросе. |
| `queue` | `default` | Имя очереди всех jobs пакета; читает `METRICA_CLIENT_VISITS_QUEUE`. |
| `cache_store` | `CACHE_STORE` или `database` | Cache store для locks экспорта; читает `METRICA_CLIENT_VISITS_CACHE_STORE`. |
| `default_attribution` | `last` | Атрибуция Logs API для batch. |
| `default_lookback_days` | `30` | Сколько дней до события включить в экспорт. |
| `default_time_tolerance_seconds` | `120` | Допуск вокруг времени события. |
| `default_goal_id` | `null` | Цель, применяемая если она не задана у события. |
| `default_selection_strategy` | `last` | Выбор лучшего визита: `last` или `first`. |
| `counter_timezone`, `goal_timezone` | `Europe/Moscow` | Часовые пояса полей `dateTime` и `goalsDateTime`, пришедших из Logs API. |
| `max_events_per_batch` | `1000` | Максимум `ClientEvent` в одном batch. |
| `max_parallel_exports_per_counter` | `1` | Число одновременных Logs-экспортов на счётчик. |
| `api_requests_per_minute_per_counter` | `30` | Лимит запросов к API на счётчик в минуту. |
| `max_days_per_export` | `365` | Максимальная длина одной экспортируемой даты; длинные периоды делятся. |
| `polling_delays` | `[15, 30, 60, 120]` | Задержки повторного опроса статуса Logs-запроса. |
| `lock_seconds` | `86400` | TTL lock одного слота экспорта. |
| `http_connect_timeout_seconds` | `10` | Connect timeout HTTP-клиента. |
| `http_timeout_seconds` | `90` | Общий HTTP timeout. |
| `job_timeout_seconds` | `110` | Timeout job пакета. |
| `queue_retry_after_seconds` | `130` | Ориентир для настройки queue connection; Laravel автоматически его не меняет. |
| `job_backoff_seconds` | `[15, 30, 60, 120, 300]` | Backoff jobs при обычных ошибках. |
| `job_retry_until_seconds` | `21600` | Максимальное окно повторов jobs в секундах. |

Для database queue должно соблюдаться соотношение:

```text
http_timeout_seconds < job_timeout_seconds < retry_after очереди Laravel
90 < 110 < 130
```

Установите `retry_after` в настройках используемого queue connection; параметр `queue_retry_after_seconds` в конфиге пакета служит только согласованным значением по умолчанию.

## Запуск сопоставления

Публичная точка входа — `ClientEventMatcher`. Каждый `ClientEvent` содержит:

| Аргумент | Тип | Правило |
| --- | --- | --- |
| `externalId` | `string` | Непустой стабильный бизнес-идентификатор, например ID сделки. |
| `clientId` | `string` | Только 6–30 цифр. |
| `occurredAtUnix` | `int` | Положительный Unix timestamp **в UTC**, в секундах. |
| `goalId` | `int|string|null` | Необязательная положительная цель Метрики. |
| `disableGoalCheck` | `bool` | При `true` отключает проверку цели для события. |

```php
use PhpDmitry\MetricaClientVisits\ClientEventMatcher;
use PhpDmitry\MetricaClientVisits\Data\BatchLookupRequest;
use PhpDmitry\MetricaClientVisits\Data\ClientEvent;

$batch = app(ClientEventMatcher::class)->start(new BatchLookupRequest(
    events: [
        new ClientEvent(
            externalId: 'amo-deal-123',
            clientId: '1234567890123456789',
            occurredAtUnix: 1_763_547_720, // 2025-11-19 10:22:00 UTC
            goalId: 42,
        ),
    ],
    counterId: 12345678,              // null — значение из config
    attribution: 'last',
    lookbackDays: 30,
    timeToleranceSeconds: 120,
    selectionStrategy: 'last',        // либо 'first'
));
```

Допустимые `attribution`: `cross_device_first`, `last`, `cross_device_last_significant`, `automatic`. `lookbackDays` и `timeToleranceSeconds` могут быть равны нулю. Если параметры не заданы, берутся соответствующие `default_*` из конфига.

Вместо контейнера можно использовать фасад для `start()`:

```php
use PhpDmitry\MetricaClientVisits\Facades\MetricaClientVisits;

$batch = MetricaClientVisits::start(new BatchLookupRequest(events: [
    new ClientEvent('amo-deal-123', '1234567890123456789', 1_763_547_720),
]));
```

`start()` сохраняет batch и ставит `StartBatchJob` в очередь. Он не ждёт ответа Метрики. Для одинакового активного запроса возвращается уже существующий batch; после `completed`, `completed_with_missing` или `failed` такой же запрос запускает новую выгрузку с тем же `externalId`.

В пакете **нет** artisan-команды `metrica-client-visits:match`: запуск выполняется через приведённый PHP API. Это удобно вызывать из controller, listener, service или собственной консольной команды приложения.

### Получение состояния и лучшего результата

```php
$batch->refresh();

$batch->status();      // queued, planning, exporting, completed, completed_with_missing или failed
$batch->isCompleted(); // true для completed, completed_with_missing и failed

$matches = $batch->matches()->get();
$missing = $batch->missingEvents()->get();
$failed = $batch->failedEvents()->get();
```

У `VisitMatch` доступны `event_id`, `candidate_id`, `visit_id`, `visit_started_at`, `duration_seconds`, `source`, `source_detail`, `utm_source`, `utm_medium`, `utm_campaign`, `referrer`, `start_url`, а также:

- `match_type`: `goal_confirmed`, `visit_contains_event`, `last_visit_before_event` или `temporal_candidate`;
- `confidence`: `high`, `medium` либо `low`;
- `reason`: `goal_not_found` или `visit_not_found` при отсутствии точного результата;
- `goal_confirmed`: подтверждена ли цель.

Если для события указана цель (в самом событии либо в `default_goal_id`), пакет сначала ищет соответствующую цель в визите в пределах допуска. Если цели нет, но временной кандидат существует, он сохраняется с `reason = goal_not_found`. При отключённой проверке цели выбор основывается только на времени визита и его длительности.

### Все визиты и история по `externalId`

Каждый найденный визит нужного `ClientID` сохраняется, даже если не стал лучшим совпадением:

```php
$candidates = $batch->candidates()->get(); // от раннего визита к позднему

foreach ($candidates as $visit) {
    $visit->visit_id;
    $visit->started_at;        // CarbonImmutable, UTC
    $visit->visit_started_at;  // совместимое имя того же времени
    $visit->duration_seconds;
    $visit->source;
    $visit->source_detail;
    $visit->utm_source;
    $visit->utm_medium;
    $visit->utm_campaign;
    $visit->referrer;
    $visit->start_url;
    $visit->goal_ids;          // array<int>
    $visit->goal_times;        // list<UTC ISO-8601 strings>
}

$history = app(ClientEventMatcher::class)
    ->candidatesForExternalId('amo-deal-123');
```

`candidatesForExternalId()` объединяет данные всех batch с этим `externalId`, оставляет единственный самый свежий вариант каждого `visit_id` и сортирует результат от раннего визита к позднему.

### Время и часовые пояса

`occurredAtUnix` всегда означает UTC-момент. Например, `1763547720` — это `2025-11-19 10:22:00 UTC` и `13:22:00 Europe/Moscow`.

`ym:s:dateTime` и `ym:s:goalsDateTime` из Logs API интерпретируются соответственно в `counter_timezone` и `goal_timezone`, затем сохраняются в UTC. Модели `StoredClientEvent`, `VisitCandidate` и `VisitMatch` также гидратируют эти поля как UTC. Поэтому при любом `APP_TIMEZONE` верно:

```php
$event->occurred_at->utc()->timestamp === $inputTimestamp;
```

Не передавайте локальное время, ошибочно записанное как Unix timestamp: пакет не может определить его исходный часовой пояс.

## Очередь и жизненный цикл

Запустите worker для выбранной очереди:

```bash
php artisan queue:work --queue=metrica-client-visits --timeout=110
```

Пайплайн выполняется так:

1. `StartBatchJob` строит UTC-период (`lookbackDays` до события и `timeToleranceSeconds` после него) и проверяет возможность выгрузки.
2. `CreateLogRequestJob` получает cache lock и создаёт Logs-запрос.
3. `PollLogRequestJob` ожидает состояние `processed`.
4. `DownloadLogRequestJob` читает TSV, оставляет строки переданных `ClientID` и создаёт или обновляет кандидатов.
5. `FinalizeBatchJob` выбирает результат по каждому событию.
6. `CleanupLogRequestJob` удаляет временный Logs-запрос в Метрике и освобождает lock.

Пакет не регистрирует расписание Laravel. При необходимости приложение может самостоятельно запускать обслуживающие команды через scheduler; это не требуется для обычного запуска batch. Очередь должна работать постоянно, иначе batch останется в промежуточном статусе.

Для HTTP 429 учитывается `Retry-After`. Другие сетевые и серверные ошибки повторяются с `job_backoff_seconds`; окно повторов ограничено `job_retry_until_seconds`. После неопределённого результата POST пакет сначала пытается найти один точный соответствующий Logs-запрос; он не создаёт второй экспорт вслепую.

## Artisan-команды

| Команда | Параметры | Что делает |
| --- | --- | --- |
| `php artisan metrica-client-visits:status <batch-uuid>` | Обязательный UUID batch | Выводит batch, число событий и результатов, период, ошибку и связанные Logs-запросы. |
| `php artisan metrica-client-visits:clean-pending` | `--batch=<batch-uuid>` — необязательно | Повторно ставит cleanup для Logs-запросов со статусом `cleanup_pending`; с опцией — только для batch. |
| `php artisan metrica-client-visits:stuck` | `--minutes=30` | Показывает незавершённые Logs-запросы, которые не обновлялись указанное число минут; при найденных строках завершается с кодом ошибки. |

Пример диагностики:

```bash
php artisan metrica-client-visits:status 2de9db60-7c64-4ad5-95e0-9d4ce5824ab8
php artisan metrica-client-visits:stuck --minutes=45
php artisan metrica-client-visits:clean-pending --batch=2de9db60-7c64-4ad5-95e0-9d4ce5824ab8
```

## Что хранится в БД

Миграции создают следующие таблицы:

| Таблица | Содержимое |
| --- | --- |
| `metrica_visit_batches` | Параметры batch, период, статус, fingerprint, ошибка и время завершения. |
| `metrica_visit_events` | Переданные события: `external_id`, `client_id`, `occurred_at`, цель и итоговый статус. |
| `metrica_visit_log_requests` | Состояние временных Logs-запросов, части TSV, размер, lock и диагностические ошибки. |
| `metrica_visit_candidates` | Все визиты найденных клиентов и нормализованные поля источника/UTM/целей. |
| `metrica_visit_matches` | Один выбранный результат на событие для удобного и обратно совместимого чтения. |

Временные поля, относящиеся к событию и визиту, хранятся и читаются как UTC. Миграция `2026_07_23_000010_repair_metrica_visit_match_utc_timestamps` безопасно восстанавливает старые `visit_started_at` из связанного `VisitCandidate`; обратный ход намеренно пустой, поскольку прежнее смещение неоднозначно.

## Диагностика и типовые проблемы

| Симптом | Что проверить |
| --- | --- |
| `Не задан metrica-client-visits.token` | `YANDEX_METRIKA_TOKEN`, конфиг-кеш и права токена. |
| Batch остаётся `queued` | Запущен ли worker нужной очереди и совпадает ли её имя с `metrica-client-visits.queue`. |
| Batch `failed` | `metrica-client-visits:status <uuid>`, `error_message` batch и связанные `metrica_visit_log_requests`. |
| `creation_uncertain` / `creation_ambiguous` | Результат POST к Logs API неизвестен или неоднозначен. Проверьте запросы в Метрике; пакет не повторяет create, чтобы не сделать дубликат. |
| `cleanup_pending` | Выполните `metrica-client-visits:clean-pending`; доступ к Logs API и worker должны быть восстановлены. |
| Визит не сопоставлен | Проверьте `ClientID`, UTC timestamp события, `counter_timezone`, окно lookback/tolerance, `goalId` и поля цели в Метрике. |
| Время сдвинуто на часы | `occurredAtUnix` передаётся в UTC; время TSV — в зоне счётчика. Не меняйте `APP_TIMEZONE` ради пакета. |
| Ошибка cache lock | Настройте доступный `cache_store`, поддерживающий locks, и выполните миграцию таблицы cache при database driver. |

Статусы `creation_uncertain`, `creation_ambiguous`, `failed` и заполненное `failure_stage` в Logs-запросе — повод разбирать первичную ошибку, а не бездумно перезапускать jobs. Повторный вызов `start()` после завершённого или failed batch создаст новый экспорт.

## Тестирование и разработка

```bash
composer install
composer test

# Или напрямую:
vendor/bin/phpunit
```

Набор тестов покрывает работу с database queue/cache, HTTP-клиент, обработку TSV, устойчивость к сбоям, повторный поиск, дедупликацию активных batch, выбор всех кандидатов и UTC-время при `APP_TIMEZONE=Europe/Moscow` и `UTC`.

Старые `metrica-logs-*.php` в корне репозитория — самостоятельный CLI-прототип. Пакет не зависит от них и не поддерживает их как публичный API.
