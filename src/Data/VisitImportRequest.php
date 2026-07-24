<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Data;

use InvalidArgumentException;
use PhpDmitry\MetricaClientVisits\Support\Attribution;

final readonly class VisitImportRequest
{
    /** @param list<VisitLookup> $lookups */
    public function __construct(
        public array $lookups,
        public string|int|null $counterId = null,
        public string|null $attribution = null,
        public int|null $lookbackDays = null,
        public int|null $timeToleranceSeconds = null,
        public string|null $selectionStrategy = null,
    ) {
        if ($this->lookups === []) {
            throw new InvalidArgumentException('В import должен быть хотя бы один VisitLookup.');
        }
        foreach ($this->lookups as $lookup) {
            if (! $lookup instanceof VisitLookup) {
                throw new InvalidArgumentException('lookups должен содержать только VisitLookup.');
            }
        }
        if ($this->counterId !== null && preg_match('/^\d+$/', (string) $this->counterId) !== 1) {
            throw new InvalidArgumentException('counterId должен состоять из цифр.');
        }
        if ($this->attribution !== null) {
            Attribution::assert($this->attribution);
        }
        if ($this->lookbackDays !== null && $this->lookbackDays < 0) {
            throw new InvalidArgumentException('lookbackDays не может быть отрицательным.');
        }
        if ($this->timeToleranceSeconds !== null && $this->timeToleranceSeconds < 0) {
            throw new InvalidArgumentException('timeToleranceSeconds не может быть отрицательным.');
        }
        if ($this->selectionStrategy !== null && ! in_array($this->selectionStrategy, ['last', 'first'], true)) {
            throw new InvalidArgumentException('selectionStrategy должен быть last или first.');
        }
    }
}
