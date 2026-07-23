<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Exceptions;

use RuntimeException;

final class LogsApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $endpoint,
        public readonly string $counterId,
        public readonly ?string $requestId = null,
        public readonly ?int $statusCode = null,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }
}
