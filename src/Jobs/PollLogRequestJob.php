<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Jobs\Concerns\UsesMetricaQueuePolicy;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;

final class PollLogRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use UsesMetricaQueuePolicy;

    public int $tries = 60;

    public function __construct(public readonly int $logRequestId)
    {
        $this->configureQueueTimeout();
    }

    public function counterId(): string { return (string) LogRequest::query()->whereKey($this->logRequestId)->with('batch')->first()?->batch?->counter_id; }

    public function handle(LogsApiClient $api): void
    {
        $request = LogRequest::query()->with('batch')->findOrFail($this->logRequestId);
        if (! in_array($request->status, ['created', 'processing'], true)) {
            return;
        }
        try {
            $response = $api->status((string) $request->batch->counter_id, (string) $request->request_id);
        } catch (\Throwable $exception) {
            if ($this->releaseRateLimitedApiFailure($exception)) {
                return;
            }
            throw $exception;
        }
        $payload = $response['log_request'] ?? $response;
        $status = (string) ($payload['status'] ?? 'created');

        if ($status === 'processed') {
            $request->update(['status' => 'processed', 'size' => $payload['size'] ?? null, 'parts' => $payload['parts'] ?? []]);
            DownloadLogRequestJob::dispatch($request->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
            return;
        }
        if (in_array($status, ['failed', 'error'], true)) {
            $request->update(['status' => 'cleanup_pending', 'failure_stage' => 'poll', 'error_message' => "Logs API status: {$status}"]);
            CleanupLogRequestJob::dispatch($request->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
            return;
        }

        $attempts = (int) $request->poll_attempts + 1;
        $delays = (array) config('metrica-client-visits.polling_delays', [15, 30, 60, 120]);
        $delay = (int) ($delays[min($attempts - 1, count($delays) - 1)] ?? 120);
        $request->update(['status' => 'processing', 'poll_attempts' => $attempts]);
        $this->release($delay);
    }

    public function failed(\Throwable $exception): void
    {
        $request = LogRequest::query()->find($this->logRequestId);
        if ($request !== null) {
            if ($request->request_id !== null) {
                $request->update(['status' => 'cleanup_pending', 'failure_stage' => 'poll', 'error_message' => $exception->getMessage()]);
                CleanupLogRequestJob::dispatch($request->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
            } else {
                $request->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
                FinalizeBatchJob::dispatch($request->batch_id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
            }
        }
    }
}
