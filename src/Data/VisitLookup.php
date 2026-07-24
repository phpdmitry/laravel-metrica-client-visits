<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Описывает внутреннее событие клиента, для которого нужно найти визиты. */
final readonly class VisitLookup
{
    public function __construct(
        public string $clientId,
        public int $occurredAtUnix,
        public string $eventName = 'Целевое действие',
        public int|string|null $goalId = null,
    ) {
        if (preg_match('/^\d{6,30}$/', $this->clientId) !== 1) {
            throw new InvalidArgumentException('clientId должен состоять из 6–30 цифр.');
        }
        if ($this->occurredAtUnix <= 0) {
            throw new InvalidArgumentException('occurredAtUnix должен быть положительным Unix timestamp.');
        }
        if (trim($this->eventName) === '') {
            throw new InvalidArgumentException('eventName не может быть пустым.');
        }
        if ($this->goalId !== null && (! is_numeric($this->goalId) || (int) $this->goalId <= 0)) {
            throw new InvalidArgumentException('goalId должен быть положительным числом или null.');
        }
    }

    public function occurredAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->occurredAtUnix))->setTimezone(new DateTimeZone('UTC'));
    }

    /** Стабильный внутренний ключ для совместимой колонки legacy external_id. */
    public function key(): string
    {
        return hash('sha256', implode('|', [$this->clientId, $this->occurredAtUnix, $this->eventName]));
    }
}
