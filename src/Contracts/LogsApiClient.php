<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Contracts;

interface LogsApiClient
{
    /** @return array<string, mixed> */
    public function evaluate(string $counterId, string $date1, string $date2, array $fields, string $source = 'visits'): array;

    /** @return array<string, mixed> */
    public function create(string $counterId, string $date1, string $date2, array $fields, string $attribution, string $source = 'visits'): array;

    /** @return array<string, mixed> */
    public function list(string $counterId): array;

    /** @return array<string, mixed> */
    public function status(string $counterId, string $requestId): array;

    /** @return resource|string Временный поток TSV или строка (удобно для тестовой реализации). */
    public function download(string $counterId, string $requestId, int $partNumber);

    public function clean(string $counterId, string $requestId): void;
}
