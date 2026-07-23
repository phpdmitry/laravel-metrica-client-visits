<?php

declare(strict_types=1);

/**
 * Выводит неагрегированные визиты конкретного ClientID из Logs API Яндекс.Метрики.
 *
 * Запуск:
 *   php metrica-source-by-client.php <clientId> <date1> <date2>
 *
 * Пример:
 *   php metrica-source-by-client.php 1700000000000000000 2026-01-01 2026-07-21
 */

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name !== '' && getenv($name) === false) {
            putenv("{$name}={$value}");
        }
    }
}

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "Ошибка: {$message}\n");
    exit($exitCode);
}

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** @return array<string, mixed> */
function metricaJson(string $method, string $url, string $token): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        fail('Не удалось инициализировать cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ["Authorization: OAuth {$token}", 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);

    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        fail("Ошибка соединения с API: {$curlError}");
    }

    $json = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        $apiMessage = is_array($json) ? ($json['message'] ?? $json['errors'][0]['message'] ?? null) : null;
        fail("API вернул HTTP {$status}" . ($apiMessage ? ": {$apiMessage}" : "\n{$body}"), 2);
    }

    return $json;
}

function downloadPart(string $url, string $token): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        fail('Не удалось инициализировать cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => ["Authorization: OAuth {$token}", 'Accept: text/plain'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);

    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        fail("Ошибка загрузки лога: {$curlError}");
    }
    if ($status < 200 || $status >= 300) {
        fail("Загрузка лога вернула HTTP {$status}: {$body}", 2);
    }

    return $body;
}

loadEnv(__DIR__ . '/.env');

if (!extension_loaded('curl')) {
    fail('В PHP не включено расширение cURL. Установите или включите php-curl.');
}

$clientId = $argv[1] ?? '';
$date1 = $argv[2] ?? '';
$date2 = $argv[3] ?? '';
$attribution = $argv[4] ?? 'last';

if (preg_match('/^\d{1,20}$/', $clientId) !== 1) {
    fail('clientId должен состоять только из цифр (до 20 знаков).');
}

if (!isValidDate($date1) || !isValidDate($date2)) {
    fail('Для Logs API обязательно передайте даты: YYYY-MM-DD YYYY-MM-DD.');
}

$token = trim((string) getenv('YANDEX_METRIKA_TOKEN'));
$counterId = trim((string) getenv('YANDEX_METRIKA_COUNTER_ID'));

if ($token === '' || $counterId === '') {
    fail('Заполните YANDEX_METRIKA_TOKEN и YANDEX_METRIKA_COUNTER_ID в .env.');
}

if (preg_match('/^\d+$/', $counterId) !== 1) {
    fail('YANDEX_METRIKA_COUNTER_ID должен состоять только из цифр.');
}

if ($date1 > $date2) {
    fail('Дата начала не может быть позже даты окончания.');
}

$intervalDays = (new DateTimeImmutable($date1))->diff(new DateTimeImmutable($date2))->days;
if ($intervalDays > 365) {
    fail('Logs API принимает период не более одного года.');
}

$baseUrl = "https://api-metrika.yandex.net/management/v1/counter/{$counterId}";
$allowedAttributions = ['cross_device_first', 'last', 'cross_device_last_significant', 'automatic'];
if (!in_array($attribution, $allowedAttributions, true)) {
    fail('Недопустимая атрибуция. Доступны: ' . implode(', ', $allowedAttributions));
}
$fields = [
    'ym:s:visitID',
    'ym:s:dateTime',
    'ym:s:clientID',
    'ym:s:pageViews',
    'ym:s:visitDuration',
    'ym:s:startURL',
    'ym:s:referer',
    'ym:s:<attribution>TrafficSource',
    'ym:s:<attribution>SourceEngine',
    'ym:s:<attribution>UTMSource',
    'ym:s:<attribution>UTMMedium',
    'ym:s:<attribution>UTMCampaign',
];
$params = [
    'date1' => $date1,
    'date2' => $date2,
    'source' => 'visits',
    'fields' => implode(',', $fields),
    'attribution' => $attribution,
];

// Logs API не фильтрует выдачу по ClientID на стороне Метрики: выгружается
// выбранный период, а ниже оставляются строки нужного ClientID.
$createUrl = $baseUrl . '/logrequests?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
$created = metricaJson('POST', $createUrl, $token);
$requestId = $created['log_request']['request_id'] ?? null;
if (!is_int($requestId) && !ctype_digit((string) $requestId)) {
    fail('В ответе создания лога нет request_id.');
}
$requestId = (string) $requestId;

fwrite(STDERR, "Logs API: создана выгрузка #{$requestId}, ожидаю подготовки...\n");
$request = [];
for ($attempt = 1; $attempt <= 30; $attempt++) {
    sleep(2);
    $statusResponse = metricaJson('GET', "{$baseUrl}/logrequest/{$requestId}", $token);
    $request = $statusResponse['log_request'] ?? [];
    $status = $request['status'] ?? '';

    if ($status === 'processed') {
        break;
    }
    if (in_array($status, ['processing_failed', 'cleaned_by_user', 'cleaned_automatically_as_too_old'], true)) {
        fail("Подготовка лога #{$requestId} завершилась со статусом {$status}.");
    }
}

if (($request['status'] ?? '') !== 'processed') {
    fail("Лог #{$requestId} ещё не подготовлен. Повторите команду позже; запрос останется в Logs API.");
}

$matches = [];
foreach (($request['parts'] ?? []) as $part) {
    $partNumber = $part['part_number'] ?? null;
    if (!is_int($partNumber) && !ctype_digit((string) $partNumber)) {
        continue;
    }

    $tsv = downloadPart("{$baseUrl}/logrequest/{$requestId}/part/{$partNumber}/download", $token);
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        fail('Не удалось обработать загруженный лог.');
    }
    fwrite($handle, $tsv);
    rewind($handle);

    $headers = fgetcsv($handle, separator: "\t");
    if ($headers === false) {
        fclose($handle);
        continue;
    }

    while (($row = fgetcsv($handle, separator: "\t")) !== false) {
        $record = array_combine($headers, $row);
        if ($record !== false && ($record['ym:s:clientID'] ?? '') === $clientId) {
            $matches[] = $record;
        }
    }
    fclose($handle);
}

// Освобождаем квоту подготовленного Logs API файла после успешной загрузки.
metricaJson('POST', "{$baseUrl}/logrequest/{$requestId}/clean", $token);

echo json_encode([
    'request_id' => (int) $requestId,
    'client_id' => $clientId,
    'date1' => $date1,
    'date2' => $date2,
    'visits_found' => count($matches),
    'visits' => $matches,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
