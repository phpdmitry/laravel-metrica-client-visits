<?php

declare(strict_types=1);

require __DIR__ . '/metrica-logs-common.php';

[$script, $date1, $date2] = array_pad($argv, 3, '');
if (!logsIsValidDate($date1) || !logsIsValidDate($date2) || $date1 > $date2) {
    logsFail('Передайте период: php metrica-logs-create.php YYYY-MM-DD YYYY-MM-DD');
}
if ($date2 >= (new DateTimeImmutable('today'))->format('Y-m-d')) {
    logsFail('Для Logs API дата окончания должна быть раньше текущего дня.');
}
if ((new DateTimeImmutable($date1))->diff(new DateTimeImmutable($date2))->days > 365) {
    logsFail('Logs API принимает период не более одного года.');
}

$config = logsConfig();
$attribution = logsAttribution($argv[3] ?? 'last');
$params = [
    'date1' => $date1,
    'date2' => $date2,
    'source' => 'visits',
    'fields' => implode(',', logsVisitFields()),
    'attribution' => $attribution,
];
$url = $config['baseUrl'] . '/logrequests?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
$response = logsApiJson('POST', $url, $config['token']);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
