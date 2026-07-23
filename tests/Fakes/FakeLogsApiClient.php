<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Fakes;

use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;

final class FakeLogsApiClient implements LogsApiClient
{
    /** @var list<string> */
    public array $calls = [];
    /** @param list<string> $parts */
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
        return ['log_request' => ['request_id' => 999, 'status' => 'created']];
    }
    public function status(string $counterId, string $requestId): array
    {
        $this->calls[] = 'status';
        $parts = array_map(static fn (string $part, int $number): array => ['part_number' => $number, 'size' => strlen($part)], $this->parts, array_keys($this->parts));
        return ['log_request' => ['request_id' => 999, 'status' => 'processed', 'size' => array_sum(array_map('strlen', $this->parts)), 'parts' => $parts]];
    }
    public function download(string $counterId, string $requestId, int $partNumber): string
    {
        $this->calls[] = 'download';
        return $this->parts[$partNumber] ?? '';
    }
    public function clean(string $counterId, string $requestId): void
    {
        $this->calls[] = 'clean';
    }
}
