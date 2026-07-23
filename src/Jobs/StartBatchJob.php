<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Data\ClientEvent;
use PhpDmitry\MetricaClientVisits\Jobs\Concerns\UsesMetricaQueuePolicy;
use PhpDmitry\MetricaClientVisits\Models\BatchLookup;
use PhpDmitry\MetricaClientVisits\Support\ExportPeriodPlanner;
use PhpDmitry\MetricaClientVisits\Support\LogsFields;

final class StartBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use UsesMetricaQueuePolicy;

    public int $tries = 3;

    public function __construct(public readonly string $batchId)
    {
        $this->configureQueueTimeout();
    }

    public function counterId(): string
    {
        return (string) BatchLookup::query()->whereKey($this->batchId)->value('counter_id');
    }

    public function handle(LogsApiClient $api, ExportPeriodPlanner $planner): void
    {
        $batch = BatchLookup::query()->with('events')->findOrFail($this->batchId);
        if ($batch->status !== 'queued') {
            return;
        }
        try {
            $batch->update(['status' => 'planning']);
            $events = $batch->events->map(fn ($event): ClientEvent => new ClientEvent($event->external_id, $event->client_id, $event->occurred_at->utc()->getTimestamp(), $event->goal_id, $event->disable_goal_check))->all();
            $periods = $planner->initialPeriods($events, (int) $batch->lookback_days, (int) $batch->time_tolerance_seconds, (int) config('metrica-client-visits.max_days_per_export', 365));
            $planned = [];

            foreach ($periods as $period) {
                foreach ($this->evaluateAndSplit($api, (string) $batch->counter_id, $period, $planner) as $approved) {
                    $planned[] = $approved;
                }
            }
            if ($planned === []) {
                throw new \RuntimeException('Logs API не разрешил создать выгрузку ни за один день периода.');
            }

            $batch->update(['status' => 'exporting', 'planned_date1' => $planned[0]['date1'], 'planned_date2' => $planned[array_key_last($planned)]['date2']]);
            foreach ($planned as $period) {
                $logRequest = $batch->logRequests()->create(['date1' => $period['date1'], 'date2' => $period['date2'], 'status' => 'planned']);
                CreateLogRequestJob::dispatch($logRequest->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
            }
        } catch (\Throwable $exception) {
            if ($this->releaseRateLimitedApiFailure($exception)) {
                return;
            }
            throw $exception;
        }
    }

    /** @param array{date1:string,date2:string} $period @return list<array{date1:string,date2:string}> */
    private function evaluateAndSplit(LogsApiClient $api, string $counterId, array $period, ExportPeriodPlanner $planner): array
    {
        $result = $api->evaluate($counterId, $period['date1'], $period['date2'], LogsFields::visits());
        $evaluation = $result['log_request_evaluation'] ?? [];
        if (($evaluation['possible'] ?? false) === true) {
            return [$period];
        }
        $maxDays = (int) ($evaluation['max_possible_day_quantity'] ?? 0);
        if ($maxDays < 1 || $period['date1'] === $period['date2']) {
            throw new \RuntimeException("Logs API не может подготовить период {$period['date1']}—{$period['date2']}.");
        }
        $from = new \DateTimeImmutable($period['date1'], new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable($period['date2'], new \DateTimeZone('UTC'));
        $approved = [];
        foreach ($planner->split($from, $to, $maxDays) as $subPeriod) {
            array_push($approved, ...$this->evaluateAndSplit($api, $counterId, $subPeriod, $planner));
        }
        return $approved;
    }

    public function failed(\Throwable $exception): void
    {
        BatchLookup::query()->whereKey($this->batchId)->update(['status' => 'failed', 'active_fingerprint' => BatchLookup::releasedFingerprint($this->batchId), 'error_message' => $exception->getMessage(), 'completed_at' => now()]);
    }
}
