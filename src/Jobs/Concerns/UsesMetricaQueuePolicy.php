<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Jobs\Concerns;

use DateTimeInterface;
use Illuminate\Queue\Middleware\RateLimited;
use PhpDmitry\MetricaClientVisits\Exceptions\LogsApiException;

trait UsesMetricaQueuePolicy
{
    public int $timeout = 110;

    /** @return list<RateLimited> */
    public function middleware(): array
    {
        return [new RateLimited('metrica-client-visits')];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('metrica-client-visits.job_backoff_seconds', [15, 30, 60, 120, 300])));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds((int) config('metrica-client-visits.job_retry_until_seconds', 21_600));
    }

    protected function configureQueueTimeout(): void
    {
        $this->timeout = (int) config('metrica-client-visits.job_timeout_seconds', 110);
    }

    /** Returns true when the job was released instead of failed. */
    protected function releaseRateLimitedApiFailure(\Throwable $exception): bool
    {
        if (! $exception instanceof LogsApiException || ! $exception->isRateLimited()) {
            return false;
        }

        $this->release($exception->retryAfterSeconds ?? 120);
        return true;
    }
}
