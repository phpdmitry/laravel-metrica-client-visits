<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpDmitry\MetricaClientVisits\Data\BatchLookupRequest;
use PhpDmitry\MetricaClientVisits\Jobs\StartBatchJob;
use PhpDmitry\MetricaClientVisits\Models\BatchLookup;

final class ClientEventMatcher
{
    public function start(BatchLookupRequest $request): BatchLookup
    {
        $maxEvents = (int) config('metrica-client-visits.max_events_per_batch', 1000);
        if (count($request->events) > $maxEvents) {
            throw new InvalidArgumentException("В одном batch разрешено не больше {$maxEvents} событий.");
        }

        $counterId = (string) ($request->counterId ?? config('metrica-client-visits.counter_id'));
        if (preg_match('/^\d+$/', $counterId) !== 1) {
            throw new InvalidArgumentException('Не задан корректный counterId.');
        }
        $attribution = $request->attribution ?? (string) config('metrica-client-visits.default_attribution', 'last');
        $lookback = $request->lookbackDays ?? (int) config('metrica-client-visits.default_lookback_days', 30);
        $tolerance = $request->timeToleranceSeconds ?? (int) config('metrica-client-visits.default_time_tolerance_seconds', 120);
        $fingerprint = hash('sha256', json_encode([$counterId, $attribution, $lookback, $tolerance, array_map(static fn ($event) => [$event->externalId, $event->clientId, $event->occurredAtUnix, $event->goalId, $event->disableGoalCheck], $request->events)], JSON_THROW_ON_ERROR));

        $batch = DB::transaction(function () use ($request, $counterId, $attribution, $lookback, $tolerance, $fingerprint): BatchLookup {
            $existing = BatchLookup::query()->where('fingerprint', $fingerprint)->first();
            if ($existing instanceof BatchLookup) {
                return $existing;
            }
            $batch = BatchLookup::query()->create([
                'id' => (string) Str::uuid(), 'fingerprint' => $fingerprint, 'counter_id' => $counterId,
                'attribution' => $attribution, 'lookback_days' => $lookback, 'time_tolerance_seconds' => $tolerance,
                'planned_date1' => now('UTC')->toDateString(), 'planned_date2' => now('UTC')->toDateString(), 'status' => 'queued',
            ]);
            foreach ($request->events as $event) {
                $batch->events()->create([
                    'external_id' => $event->externalId, 'client_id' => $event->clientId,
                    'occurred_at' => $event->occurredAt(), 'goal_id' => $event->goalId,
                    'disable_goal_check' => $event->disableGoalCheck,
                ]);
            }
            $batch->setAttribute('_new_lookup', true);
            return $batch;
        });

        if ($batch->getAttribute('_new_lookup') === true) {
            StartBatchJob::dispatch($batch->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
        }

        return $batch;
    }
}
