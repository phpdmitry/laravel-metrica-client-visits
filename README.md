# Laravel Metrica Client Visits

Пакет для Laravel 12–13, который находит источник визита Яндекс.Метрики по `ClientID` и времени события. Он не является SDK Метрики: поддерживает только batch-поиск визитов через Logs API.

Вход — список событий `ClientID + Unix timestamp (UTC)`. Пакет строит один или несколько периодов выгрузки, асинхронно получает TSV через Logs API, оставляет только визиты нужных клиентов, выбирает подходящий визит и очищает временную выгрузку в Метрике.

Полные логи счётчика не сохраняются.

## Установка

```bash
composer require phpdmitry/laravel-metrica-client-visits
php artisan vendor:publish --tag=metrica-client-visits-config
php artisan migrate
```

Пакет использует обычные Laravel queue и cache. Redis не нужен. Для database-драйверов в приложении один раз создайте стандартные таблицы Laravel:

```bash
php artisan make:queue-table
php artisan make:cache-table
php artisan migrate
```

Запустите worker:

```bash
php artisan queue:work --timeout=110
```

## Конфигурация

В `.env` приложения задайте OAuth-токен Метрики с правом `metrika:read` и номер счётчика:

```dotenv
YANDEX_METRIKA_TOKEN=...
YANDEX_METRIKA_COUNTER_ID=12345678

QUEUE_CONNECTION=database
CACHE_STORE=database
DB_QUEUE_RETRY_AFTER=130
```

OAuth `client_id` и `client_secret` этому пакету не нужны: OAuth-flow он намеренно не выполняет. Все опции находятся в `config/metrica-client-visits.php`, в том числе часовой пояс счётчика, цель по умолчанию, лимит параллельных выгрузок и интервалы polling.

Нужно соблюдать: `HTTP timeout (90) < job timeout (110) < DB_QUEUE_RETRY_AFTER (130)`. Параметры `http_timeout_seconds`, `job_timeout_seconds` и `queue_retry_after_seconds` находятся в конфиге пакета; последнее значение служит ориентиром и не переопределяет настройку Laravel автоматически.

## Использование

```php
use PhpDmitry\MetricaClientVisits\ClientEventMatcher;
use PhpDmitry\MetricaClientVisits\Data\BatchLookupRequest;
use PhpDmitry\MetricaClientVisits\Data\ClientEvent;

$batch = app(ClientEventMatcher::class)->start(new BatchLookupRequest(
    events: [
        new ClientEvent(
            externalId: 'amo-deal-123',
            clientId: '1234567890123456789',
            occurredAtUnix: 1700000000,
            goalId: 42, // null — использовать цель из config; disableGoalCheck: true — не проверять цель
        ),
    ],
    attribution: 'last',
    lookbackDays: 30,
    timeToleranceSeconds: 120,
));
```

Методы `$batch->status()`, `$batch->isCompleted()`, `$batch->matches()`, `$batch->missingEvents()` и `$batch->failedEvents()` дают состояние задачи и результаты. В `metrica_visit_matches` доступны нормализованные поля: `source`, `source_detail`, `utm_source`, `utm_medium`, `utm_campaign`, `referrer`, `start_url` — без ключей `<attribution>` из ответа Logs API.

Если указана цель, точным совпадением считается достижение этой цели рядом со временем события. Если цели в логе нет, событие получает `reason = goal_not_found`, но лучший временной кандидат всё равно сохраняется как `temporal_candidate`.

## Обслуживание

```bash
php artisan metrica-client-visits:status <batch-uuid>
php artisan metrica-client-visits:clean-pending
php artisan metrica-client-visits:stuck --minutes=30
```

## Устойчивость к сбоям

- Для HTTP 429 учитывается заголовок `Retry-After`; остальные сетевые и серверные ошибки повторяются с backoff `15, 30, 60, 120, 300` секунд.
- POST создания выгрузки не повторяется автоматически при неопределённом результате. Сначала пакет получает список Logs-запросов и ищет точное совпадение. При отсутствии или неоднозначности совпадения запрос получает статус `creation_uncertain` или `creation_ambiguous`, а batch — `failed`, без риска создать дубликат.
- Ошибки polling и загрузки TSV ставят cleanup в очередь: уже созданный удалённый файл очищается даже при неуспешном batch.
- Внешние запросы ограничены по счётчику через Laravel rate limiter; экспорт дополнительно удерживает cache lock до cleanup.

## Совместимость и разработка

- Laravel 12: PHP 8.2–8.5.
- Laravel 13: PHP 8.3–8.5.

```bash
composer install
composer test
```

Старые `metrica-logs-*.php` в корне — самостоятельный CLI-прототип. Пакет на них не опирается и не поддерживает их как публичный API.
