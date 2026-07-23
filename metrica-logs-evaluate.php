<?php

declare(strict_types=1);

/**
 * Проверяет, можно ли создать выгрузку Logs API за указанный период.
 *
 * Запуск:
 *   php metrica-logs-evaluate.php <date1> <date2>
 *
 * Пример:
 *   php metrica-logs-evaluate.php 2026-04-21 2026-04-28
 */

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "Ошибка: {$message}\n");
    exit($exitCode);
}

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fail("Не удалось прочитать {$path}");
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

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

loadEnv(__DIR__ . '/.env');

if (!extension_loaded('curl')) {
    fail('В PHP не включено расширение cURL. Установите или включите php-curl.');
}

[$script, $date1, $date2] = array_pad($argv, 3, '');
if (!isValidDate($date1) || !isValidDate($date2) || $date1 > $date2) {
    fail('Передайте период: php metrica-logs-evaluate.php YYYY-MM-DD YYYY-MM-DD');
}

$token = trim((string) getenv('YANDEX_METRIKA_TOKEN'));
$counterId = trim((string) getenv('YANDEX_METRIKA_COUNTER_ID'));
if ($token === '' || $counterId === '') {
    fail('Заполните YANDEX_METRIKA_TOKEN и YANDEX_METRIKA_COUNTER_ID в .env.');
}
if (preg_match('/^\d+$/', $counterId) !== 1) {
    fail('YANDEX_METRIKA_COUNTER_ID должен состоять только из цифр.');
}

$attribution = $argv[3] ?? 'last';
$allowedAttributions = ['cross_device_first', 'last', 'cross_device_last_significant', 'automatic'];
if (!in_array($attribution, $allowedAttributions, true)) {
    fail('Недопустимая атрибуция. Доступны: ' . implode(', ', $allowedAttributions));
}

// Список совпадает с полями основного скрипта metrica-source-by-client.php.
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
$url = "https://api-metrika.yandex.net/management/v1/counter/{$counterId}/logrequests/evaluate?"
    . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

$curl = curl_init($url);
if ($curl === false) {
    fail('Не удалось инициализировать cURL.');
}
curl_setopt_array($curl, [
    CURLOPT_HTTPHEADER => ["Authorization: OAuth {$token}", 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
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

echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
