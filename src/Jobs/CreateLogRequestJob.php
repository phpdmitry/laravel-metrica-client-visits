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
use PhpDmitry\MetricaClientVisits\Exceptions\LogsApiException;
use PhpDmitry\MetricaClientVisits\Jobs\Concerns\UsesMetricaQueuePolicy;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;
use PhpDmitry\MetricaClientVisits\Support\LogsFields;

final class CreateLogRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use UsesMetricaQueuePolicy;

    public int $tries = 5;

    public function __construct(public readonly int $logRequestId)
    {
        $this->configureQueueTimeout();
    }

    public function counterId(): string
    {
        return (string) LogRequest::query()->whereKey($this->logRequestId)->with('batch')->first()?->batch?->counter_id;
    }

    public function handle(LogsApiClient $api): void
    {
        $request = LogRequest::query()->with('batch')->findOrFail($this->logRequestId);
        if (! in_array($request->status, ['planned', 'waiting_lock', 'creating'], true)) {
            return;
        }
        [$lock, $key] = $request->status === 'creating' && $request->lock_key !== null && $request->lock_owner !== null
            ? [Cache::store((string) config('metrica-client-visits.cache_store', 'database'))->restoreLock($request->lock_key, $request->lock_owner), $request->lock_key]
            : $this->acquireSlot((string) $request->batch->counter_id);
        if (! $lock instanceof Lock) {
            $request->update(['status' => 'waiting_lock']);
            $this->release(15);
            return;
        }

        try {
            if ($request->status === 'creating') {
                $this->reconcileCreation($request, $api, $lock);
                return;
            }
            $request->update([
                'status' => 'creating', 'create_attempted_at' => now(), 'creation_token' => (string) \Illuminate\Support\Str::uuid(),
                'lock_key' => $key, 'lock_owner' => $lock->owner(), 'error_message' => null,
            ]);
            $response = $api->create((string) $request->batch->counter_id, $request->date1->toDateString(), $request->date2->toDateString(), LogsFields::visits(), $request->batch->attribution);
            $payload = $response['log_request'] ?? $response;
            $request->update(['request_id' => $payload['request_id'], 'status' => 'created']);
            PollLogRequestJob::dispatch($request->id)->delay(now()->addSeconds(15))->onQueue((string) config('metrica-client-visits.queue', 'default'));
        } catch (\Throwable $exception) {
            if ($exception instanceof LogsApiException && $exception->isRateLimited()) {
                if ($request->status === 'creating') {
                    $request->update(['error_message' => $exception->getMessage()]);
                    $this->release($exception->retryAfterSeconds ?? 120);
                    return;
                }
                $request->update(['status' => 'planned', 'error_message' => $exception->getMessage()]);
                $lock->release();
                $this->release($exception->retryAfterSeconds ?? 120);
                return;
            }
            // После неизвестного результата POST повтор сначала сделает reconciliation с тем же owner lock.
            $request->update(['status' => 'creating', 'error_message' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private function reconcileCreation(LogRequest $request, LogsApiClient $api, Lock $lock): void
    {
        $items = $api->list((string) $request->batch->counter_id)['requests'] ?? [];
        $matches = array_values(array_filter($items, function (mixed $item) use ($request): bool {
            if (! is_array($item)) {
                return false;
            }
            $fields = $item['fields'] ?? [];
            sort($fields);
            $expectedFields = LogsFields::visits();
            sort($expectedFields);
            $source = $item['source'] ?? '';
            $source = is_array($source) ? (string) ($source[0] ?? '') : (string) $source;
            return (string) ($item['date1'] ?? '') === $request->date1->toDateString()
                && (string) ($item['date2'] ?? '') === $request->date2->toDateString()
                && strtolower($source) === 'visits'
                && strtoupper((string) ($item['attribution'] ?? '')) === strtoupper((string) $request->batch->attribution)
                && $fields === $expectedFields;
        }));
        if (count($matches) === 1 && isset($matches[0]['request_id'])) {
            $request->update(['request_id' => $matches[0]['request_id'], 'status' => 'created', 'error_message' => null]);
            PollLogRequestJob::dispatch($request->id)->delay(now()->addSeconds(15))->onQueue((string) config('metrica-client-visits.queue', 'default'));
            return;
        }
        $status = count($matches) === 0 ? 'creation_uncertain' : 'creation_ambiguous';
        $request->update(['status' => $status, 'error_message' => 'Нельзя безопасно восстановить результат POST /logrequests без риска создать дубликат.']);
        $lock->release();
        FinalizeBatchJob::dispatch($request->batch_id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
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
            $this->releaseSlot($request);
            FinalizeBatchJob::dispatch($request->batch_id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
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
