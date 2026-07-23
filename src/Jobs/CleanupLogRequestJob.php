<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Jobs\Concerns\UsesMetricaQueuePolicy;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;

final class CleanupLogRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use UsesMetricaQueuePolicy;

    public int $tries = 10;

    public function __construct(public readonly int $logRequestId)
    {
        $this->configureQueueTimeout();
    }

    public function counterId(): string { return (string) LogRequest::query()->whereKey($this->logRequestId)->with('batch')->first()?->batch?->counter_id; }

    public function handle(LogsApiClient $api): void
    {
        $request = LogRequest::query()->with('batch')->findOrFail($this->logRequestId);
        if (in_array($request->status, ['cleaned', 'failed'], true)) {
            return;
        }
        try {
            $api->clean((string) $request->batch->counter_id, (string) $request->request_id);
        } catch (\Throwable $exception) {
            $request->update(['status' => 'cleanup_pending', 'error_message' => $exception->getMessage()]);
            if ($this->releaseRateLimitedApiFailure($exception)) {
                return;
            }
            throw $exception;
        }

        $request->update(['status' => 'cleaned', 'cleaned_at' => now(), 'error_message' => null]);
        $this->releaseSlot($request);
        FinalizeBatchJob::dispatch($request->batch_id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
    }

    public function failed(\Throwable $exception): void
    {
        $request = LogRequest::query()->find($this->logRequestId);
        if ($request !== null) {
            $request->update(['status' => 'cleanup_pending', 'error_message' => $exception->getMessage()]);
        }
    }

    private function releaseSlot(LogRequest $request): void
    {
        if ($request->lock_key !== null && $request->lock_owner !== null) {
            Cache::store((string) config('metrica-client-visits.cache_store', 'database'))
                ->restoreLock($request->lock_key, $request->lock_owner)
                ->release();
        }
    }
}
