<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Data;

use DateTimeInterface;

final readonly class VisitMatchData
{
    /** @param array<int, int> $goalIds */
    public function __construct(
        public string $visitId,
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
    ) {
    }
}
