<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Data;

use DateTimeInterface;

/** Нормализованная строка TSV Logs API. */
final readonly class VisitData
{
    /** @param list<int> $goalIds @param list<string> $goalTimes */
    public function __construct(
        public string $visitId,
        public string $clientId,
        public DateTimeInterface $startedAt,
        public int $durationSeconds,
        public ?string $source,
        public ?string $sourceDetail,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public ?string $referrer,
        public ?string $startUrl,
        public array $goalIds = [],
        public array $goalTimes = [],
    ) {}
}
