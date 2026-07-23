<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ClientEvent
{
    public function __construct(
        public string $externalId,
        public string $clientId,
        public int $occurredAtUnix,
        public int|string|null $goalId = null,
        public bool $disableGoalCheck = false,
    ) {
        if (trim($this->externalId) === '') {
            throw new InvalidArgumentException('externalId не может быть пустым.');
        }
        if (preg_match('/^\d{6,30}$/', $this->clientId) !== 1) {
            throw new InvalidArgumentException('clientId должен состоять из 6–30 цифр.');
        }
        if ($this->occurredAtUnix <= 0) {
            throw new InvalidArgumentException('occurredAtUnix должен быть положительным Unix timestamp.');
        }
        if ($this->goalId !== null && (! is_numeric($this->goalId) || (int) $this->goalId <= 0)) {
            throw new InvalidArgumentException('goalId должен быть положительным числом или null.');
        }
    }

    public function occurredAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->occurredAtUnix))->setTimezone(new DateTimeZone('UTC'));
    }
}
