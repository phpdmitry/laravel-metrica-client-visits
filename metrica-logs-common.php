<?php

declare(strict_types=1);

/** @return never */
function logsFail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "Ошибка: {$message}\n");
    exit($exitCode);
}

function logsLoadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        logsFail("Не удалось прочитать {$path}");
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

/** @return array{token: string, counterId: string, baseUrl: string} */
function logsConfig(): array
{
    logsLoadEnv(__DIR__ . '/.env');

    if (!extension_loaded('curl')) {
        logsFail('В PHP не включено расширение cURL. Установите или включите php-curl.');
    }

    $token = trim((string) getenv('YANDEX_METRIKA_TOKEN'));
    $counterId = trim((string) getenv('YANDEX_METRIKA_COUNTER_ID'));
    if ($token === '' || $counterId === '') {
        logsFail('Заполните YANDEX_METRIKA_TOKEN и YANDEX_METRIKA_COUNTER_ID в .env.');
    }
    if (preg_match('/^\d+$/', $counterId) !== 1) {
        logsFail('YANDEX_METRIKA_COUNTER_ID должен состоять только из цифр.');
    }

    return [
        'token' => $token,
        'counterId' => $counterId,
        'baseUrl' => "https://api-metrika.yandex.net/management/v1/counter/{$counterId}",
    ];
}

function logsIsValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function logsRequestId(string $requestId): string
{
    if (preg_match('/^\d+$/', $requestId) !== 1) {
        logsFail('requestId должен состоять только из цифр.');
    }
    return $requestId;
}

function logsAttribution(string $value): string
{
    $allowed = [
        'cross_device_first',
        'last',
        'cross_device_last_significant',
        'automatic',
    ];

    if (!in_array($value, $allowed, true)) {
        logsFail('Недопустимая атрибуция. Доступны: ' . implode(', ', $allowed));
    }

    return $value;
}

/** @return array<string, mixed> */
function logsApiJson(string $method, string $url, string $token): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        logsFail('Не удалось инициализировать cURL.');
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
        logsFail("Ошибка соединения с API: {$curlError}");
    }

    $json = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        $apiMessage = is_array($json) ? ($json['message'] ?? $json['errors'][0]['message'] ?? null) : null;
        logsFail("API вернул HTTP {$status}" . ($apiMessage ? ": {$apiMessage}" : "\n{$body}"), 2);
    }

    return $json;
}

function logsDownloadPart(string $url, string $token): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        logsFail('Не удалось инициализировать cURL.');
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
        logsFail("Ошибка загрузки лога: {$curlError}");
    }
    if ($status < 200 || $status >= 300) {
        logsFail("Загрузка лога вернула HTTP {$status}: {$body}", 2);
    }

    return $body;
}

/** @return list<string> */
function logsVisitFields(): array
{
    return [
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
}
