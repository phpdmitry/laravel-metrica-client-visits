<?php

declare(strict_types=1);

require __DIR__ . '/metrica-logs-common.php';

$requestId = logsRequestId($argv[1] ?? '');
$config = logsConfig();
$response = logsApiJson('GET', "{$config['baseUrl']}/logrequest/{$requestId}", $config['token']);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

