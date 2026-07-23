<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Data;

use InvalidArgumentException;
use PhpDmitry\MetricaClientVisits\Support\Attribution;

final readonly class BatchLookupRequest
{
    /** @param list<ClientEvent> $events */
    public function __construct(
        public array $events,
        public string|int|null $counterId = null,
        public string|null $attribution = null,
        public int|null $lookbackDays = null,
        public int|null $timeToleranceSeconds = null,
        public string|null $selectionStrategy = null,
    ) {
        if ($this->events === []) {
            throw new InvalidArgumentException('В batch должен быть хотя бы один ClientEvent.');
        }
        foreach ($this->events as $event) {
            if (! $event instanceof ClientEvent) {
                throw new InvalidArgumentException('events должен содержать только ClientEvent.');
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
