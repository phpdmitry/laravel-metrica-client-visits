<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Fakes;

use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;

final class FakeLogsApiClient implements LogsApiClient
{
    /** @var list<string> */
    public array $calls = [];
    /** @param list<string> $parts */
    /** @var list<array<string, mixed>> */
    public array $requests = [];
    /** @var list<list<string>> Части TSV для последовательных созданных выгрузок. */
    public array $exports = [];
    public ?\Throwable $downloadException = null;
    private int $createdExports = 0;

    public function __construct(public array $parts = [], public bool $possible = true)
    {
    }
    public function evaluate(string $counterId, string $date1, string $date2, array $fields, string $source = 'visits'): array
    {
        $this->calls[] = 'evaluate';
        return ['log_request_evaluation' => ['possible' => $this->possible, 'max_possible_day_quantity' => 1]];
    }
    public function create(string $counterId, string $date1, string $date2, array $fields, string $attribution, string $source = 'visits'): array
    {
        $this->calls[] = 'create';
        $requestId = 999 + $this->createdExports++;
        return ['log_request' => ['request_id' => $requestId, 'status' => 'created']];
    }
    public function list(string $counterId): array
    {
        $this->calls[] = 'list';
        return ['requests' => $this->requests];
    }
    public function status(string $counterId, string $requestId): array
    {
        $this->calls[] = 'status';
        $export = $this->partsForRequest($requestId);
        $parts = array_map(static fn (string $part, int $number): array => ['part_number' => $number, 'size' => strlen($part)], $export, array_keys($export));
        return ['log_request' => ['request_id' => $requestId, 'status' => 'processed', 'size' => array_sum(array_map('strlen', $export)), 'parts' => $parts]];
    }
    public function download(string $counterId, string $requestId, int $partNumber): string
    {
        $this->calls[] = 'download';
        if ($this->downloadException !== null) {
            throw $this->downloadException;
        }
        return $this->partsForRequest($requestId)[$partNumber] ?? '';
    }
    public function clean(string $counterId, string $requestId): void
    {
        $this->calls[] = 'clean';
    }

    /** @return list<string> */
    private function partsForRequest(string $requestId): array
    {
        return $this->exports[(int) $requestId - 999] ?? $this->parts;
    }
}
