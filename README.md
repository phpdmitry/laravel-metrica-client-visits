# Laravel Metrica Client Visits

`phpdmitry/laravel-metrica-client-visits` асинхронно выгружает визиты Яндекс.Метрики по `ClientID` и сохраняет их в БД Laravel. Каждый импорт привязан к внутреннему бизнес-событию пользователя: регистрации, заявке, звонку или другому действию.

Пакет использует [Logs API](https://yandex.ru/dev/metrika/doc/api2/logs/intro.html) только для визитов (`source=visits`). Он не является универсальным SDK Метрики.

## Установка

```bash
composer require phpdmitry/laravel-metrica-client-visits
php artisan vendor:publish --tag=metrica-client-visits-config
php artisan migrate
```

Нужны OAuth-токен с правом `metrika:read`, работающий Laravel queue worker и cache store с locks.

```dotenv
YANDEX_METRIKA_TOKEN=oauth-токен-с-правом-metrika-read
YANDEX_METRIKA_COUNTER_ID=12345678
METRICA_CLIENT_VISITS_QUEUE=metrica-client-visits
METRICA_CLIENT_VISITS_COUNTER_TIMEZONE=Europe/Moscow
METRICA_CLIENT_VISITS_GOAL_TIMEZONE=Europe/Moscow
```

## Импорт визитов

Публичная точка входа — `VisitImporter`. Импорт не ждёт Метрику: он создаёт batch и ставит задачи в очередь.

```php
use PhpDmitry\MetricaClientVisits\Data\VisitImportRequest;
use PhpDmitry\MetricaClientVisits\Data\VisitLookup;
use PhpDmitry\MetricaClientVisits\VisitImporter;

$batch = app(VisitImporter::class)->start(new VisitImportRequest(
    lookups: [
        new VisitLookup(
            clientId: '1234567890123456789',
            occurredAtUnix: 1_777_386_720, // всегда UTC Unix timestamp
            eventName: 'Регистрация',
        ),
        new VisitLookup(
            clientId: '1234567890123456789',
            occurredAtUnix: 1_777_386_060,
            eventName: 'Оставил заявку',
            goalId: 42, // необязательная цель Метрики
        ),
    ],
    lookbackDays: 30,
    timeToleranceSeconds: 120,
));
```

`eventName` — внутреннее название события, а не цель Метрики. По умолчанию оно равно `Целевое действие`. Один import принимает до 1000 событий; 100 `ClientID` обрабатываются одним экспортом, если их периоды можно объединить.

Событие уникально в пределах счётчика по `client_id + occurred_at + event_name`. Повторный импорт этой же тройки заменяет её набор визитов и основной визит. Визиты, не связанные больше ни с одним событием, удаляются.

Вместо контейнера доступен фасад:

```php
use PhpDmitry\MetricaClientVisits\Facades\MetricaClientVisits;

$batch = MetricaClientVisits::start(new VisitImportRequest([
    new VisitLookup('1234567890123456789', 1_777_386_720, 'Звонок'),
]));
```

## Чтение данных из БД

`Visit` — самостоятельная постоянная модель визита. В ней доступны `client_id`, `visit_id`, `started_at`, `duration_seconds`, `source`, `source_detail`, `utm_source`, `utm_medium`, `utm_campaign`, `referrer`, `start_url`, `goal_ids` и `goal_times`.

```php
use PhpDmitry\MetricaClientVisits\Models\Visit;
use PhpDmitry\MetricaClientVisits\Models\VisitEvent;

$visits = Visit::query()
    ->whereIn('client_id', ['1234567890123456789'])
    ->orderBy('started_at')
    ->get();

foreach ($visits as $visit) {
    echo $visit->utm_source;
}

$events = VisitEvent::query()
    ->where('client_id', '1234567890123456789')
    ->with(['visits', 'primaryVisit'])
    ->get();

foreach ($events as $event) {
    $event->event_name;       // «Регистрация», «Заявка» и т. п.
    $event->visits;           // все найденные визиты для этого события
    $event->primaryVisit;     // один основной визит или null
}
```

Один `Visit` может быть связан с несколькими `VisitEvent`: например, если одинаковый визит подходит и для регистрации, и для заявки. Основной визит хранится у события, поэтому контекст не теряется.

При `goalId` сначала выбирается визит с подтверждённой целью Метрики. Если цели нет или она не найдена, используется визит, покрывающий момент события; иначе — последний визит до события в пределах `lookbackDays`. В случае не найденной цели временной кандидат остаётся основным, а у события будет `reason = goal_not_found`.

## Статус и очередь

```php
$batch->refresh();
$batch->status();      // queued, planning, exporting, completed, completed_with_missing или failed
$batch->isCompleted();

php artisan queue:work --queue=metrica-client-visits --timeout=110
```

Жизненный цикл: планирование периода → создание Logs-запроса → опрос статуса → загрузка TSV → сохранение `Visit` и связей с `VisitEvent` → выбор основного визита → удаление временного Logs-запроса в Метрике.

Полезные команды:

```bash
php artisan metrica-client-visits:status <batch-uuid>
php artisan metrica-client-visits:stuck --minutes=45
php artisan metrica-client-visits:clean-pending --batch=<batch-uuid>
```

## Миграция с прежней версии

Миграция пакета переносит старые `VisitCandidate` и `VisitMatch` в новые `Visit`, связи событий и `primary_visit_id`. Старым событиям присваивается название `Целевое действие`. Прежние таблицы намеренно не удаляются автоматически, но старые PHP API удалены: используйте `VisitImporter`, `VisitLookup`, `Visit`, `VisitEvent`.

Все временные значения хранятся и гидратируются как UTC. `occurredAtUnix` всегда передавайте в UTC.

## Тестирование

```bash
composer test
```
