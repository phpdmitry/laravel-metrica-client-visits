<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;
use PhpDmitry\MetricaClientVisits\Support\LogsFields;

final class CreateLogRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $logRequestId)
    {
    }

    public function handle(LogsApiClient $api): void
    {
        $request = LogRequest::query()->with('batch')->findOrFail($this->logRequestId);
        if (! in_array($request->status, ['planned', 'waiting_lock'], true)) {
            return;
        }
        [$lock, $key] = $this->acquireSlot((string) $request->batch->counter_id);
        if (! $lock instanceof Lock) {
            $request->update(['status' => 'waiting_lock']);
            $this->release(15);
            return;
        }

        try {
            $response = $api->create((string) $request->batch->counter_id, $request->date1->toDateString(), $request->date2->toDateString(), LogsFields::visits(), $request->batch->attribution);
            $payload = $response['log_request'] ?? $response;
            $request->update(['request_id' => $payload['request_id'], 'status' => 'created', 'lock_key' => $key, 'lock_owner' => $lock->owner()]);
            PollLogRequestJob::dispatch($request->id)->delay(now()->addSeconds(15))->onQueue((string) config('metrica-client-visits.queue', 'default'));
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }
    }

    /** @return array{0:?Lock,1:?string} */
    private function acquireSlot(string $counterId): array
    {
        $store = (string) config('metrica-client-visits.cache_store', 'database');
        $ttl = (int) config('metrica-client-visits.lock_seconds', 3600);
        $slots = max(1, (int) config('metrica-client-visits.max_parallel_exports_per_counter', 1));
        for ($slot = 1; $slot <= $slots; $slot++) {
            $key = "metrica-client-visits:export:{$counterId}:{$slot}";
            $lock = Cache::store($store)->lock($key, $ttl);
            if ($lock->get()) {
                return [$lock, $key];
            }
        }
        return [null, null];
    }

    public function failed(\Throwable $exception): void
    {
        $request = LogRequest::query()->find($this->logRequestId);
        if ($request !== null) {
            $request->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            FinalizeBatchJob::dispatch($request->batch_id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
        }
    }
}
