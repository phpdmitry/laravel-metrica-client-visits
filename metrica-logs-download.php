<?php

declare(strict_types=1);

require __DIR__ . '/metrica-logs-common.php';

$requestId = logsRequestId($argv[1] ?? '');
$clientId = $argv[2] ?? '';
if (preg_match('/^\d{1,20}$/', $clientId) !== 1) {
    logsFail('clientId должен состоять только из цифр (до 20 знаков).');
}

$config = logsConfig();
$response = logsApiJson('GET', "{$config['baseUrl']}/logrequest/{$requestId}", $config['token']);
$request = $response['log_request'] ?? [];
if (($request['status'] ?? '') !== 'processed') {
    logsFail('Лог ещё не готов. Проверьте статус: php metrica-logs-status.php ' . $requestId);
}

$matches = [];
foreach (($request['parts'] ?? []) as $part) {
    $partNumber = $part['part_number'] ?? null;
    if (!is_int($partNumber) && !ctype_digit((string) $partNumber)) {
        continue;
    }

    $tsv = logsDownloadPart("{$config['baseUrl']}/logrequest/{$requestId}/part/{$partNumber}/download", $config['token']);
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        logsFail('Не удалось обработать загруженный лог.');
    }
    fwrite($handle, $tsv);
    rewind($handle);
    $headers = fgetcsv($handle, separator: "\t", escape: "\\");

    if ($headers !== false) {
        while (($row = fgetcsv($handle, separator: "\t", escape: "\\")) !== false) {
            if (count($headers) !== count($row)) {
                continue;
            }
            $record = array_combine($headers, $row);
            if ($record !== false && ($record['ym:s:clientID'] ?? '') === $clientId) {
                $matches[] = $record;
            }
        }
    }
    fclose($handle);
}

echo json_encode([
    'request_id' => (int) $requestId,
    'client_id' => $clientId,
    'visits_found' => count($matches),
    'visits' => $matches,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
