<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Jobs;

use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;
use PhpDmitry\MetricaClientVisits\Models\VisitCandidate;
use PhpDmitry\MetricaClientVisits\Support\TsvVisitParser;

final class DownloadLogRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $logRequestId)
    {
    }

    public function handle(LogsApiClient $api, TsvVisitParser $parser): void
    {
        $request = LogRequest::query()->with(['batch', 'batch.events'])->findOrFail($this->logRequestId);
        if ($request->status !== 'processed') {
            return;
        }
        $request->update(['status' => 'downloading']);
        $eventsByClientId = $request->batch->events->groupBy('client_id');
        $counterZone = new DateTimeZone((string) config('metrica-client-visits.counter_timezone', 'Europe/Moscow'));
        $goalZone = new DateTimeZone((string) config('metrica-client-visits.goal_timezone', 'Europe/Moscow'));

        foreach ($request->parts ?? [] as $part) {
            $partNumber = (int) ($part['part_number'] ?? 0);
            $tsv = $api->download((string) $request->batch->counter_id, (string) $request->request_id, $partNumber);
            try {
                foreach ($parser->rows($tsv) as $row) {
                    $clientId = (string) ($row['ym:s:clientID'] ?? '');
                    if (! $eventsByClientId->has($clientId)) {
                        continue;
                    }
                    $visit = $parser->visit($row, $counterZone, $goalZone);
                    if ($visit->visitId === '') {
                        continue;
                    }
                    foreach ($eventsByClientId->get($clientId) as $event) {
                        VisitCandidate::query()->updateOrCreate(
                            ['event_id' => $event->id, 'visit_id' => $visit->visitId],
                            [
                                'log_request_id' => $request->id, 'started_at' => $visit->startedAt,
                                'duration_seconds' => $visit->durationSeconds, 'source' => $visit->source,
                                'source_detail' => $visit->sourceDetail, 'utm_source' => $visit->utmSource,
                                'utm_medium' => $visit->utmMedium, 'utm_campaign' => $visit->utmCampaign,
                                'referrer' => $visit->referrer, 'start_url' => $visit->startUrl,
                                'goal_ids' => $visit->goalIds, 'goal_times' => $visit->goalTimes,
                            ],
                        );
                    }
                }
            } finally {
                if (is_resource($tsv)) {
                    fclose($tsv);
                }
            }
        }

        $request->update(['status' => 'downloaded']);
        // Итог нужен пользователю даже тогда, когда очистка Logs API временно недоступна.
        FinalizeBatchJob::dispatch($request->batch_id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
        CleanupLogRequestJob::dispatch($request->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
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
