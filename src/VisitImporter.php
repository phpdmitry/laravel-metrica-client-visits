<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpDmitry\MetricaClientVisits\Data\VisitImportRequest;
use PhpDmitry\MetricaClientVisits\Jobs\StartBatchJob;
use PhpDmitry\MetricaClientVisits\Models\BatchLookup;
use PhpDmitry\MetricaClientVisits\Models\Visit;
use PhpDmitry\MetricaClientVisits\Models\VisitEvent;

/** Асинхронно импортирует визиты для внутренних событий клиента. */
final class VisitImporter
{
    public function start(VisitImportRequest $request): BatchLookup
    {
        $maxEvents = (int) config('metrica-client-visits.max_events_per_batch', 1000);
        if (count($request->lookups) > $maxEvents) {
            throw new InvalidArgumentException("В одном import разрешено не больше {$maxEvents} событий.");
        }

        $counterId = (string) ($request->counterId ?? config('metrica-client-visits.counter_id'));
        if (preg_match('/^\d+$/', $counterId) !== 1) {
            throw new InvalidArgumentException('Не задан корректный counterId.');
        }
        $attribution = $request->attribution ?? (string) config('metrica-client-visits.default_attribution', 'last');
        $lookback = $request->lookbackDays ?? (int) config('metrica-client-visits.default_lookback_days', 30);
        $tolerance = $request->timeToleranceSeconds ?? (int) config('metrica-client-visits.default_time_tolerance_seconds', 120);
        $selectionStrategy = $request->selectionStrategy ?? (string) config('metrica-client-visits.default_selection_strategy', 'last');
        $keys = array_map(static fn ($lookup): array => [$lookup->clientId, $lookup->occurredAtUnix, $lookup->eventName, $lookup->goalId], $request->lookups);
        if (count(array_unique(array_map(static fn (array $key): string => implode('|', $key), $keys))) !== count($keys)) {
            throw new InvalidArgumentException('Один import не может содержать одинаковые clientId, время и eventName.');
        }
        $fingerprint = hash('sha256', json_encode([$counterId, $attribution, $lookback, $tolerance, $selectionStrategy, $keys], JSON_THROW_ON_ERROR));

        try {
            $batch = DB::transaction(function () use ($request, $counterId, $attribution, $lookback, $tolerance, $selectionStrategy, $fingerprint): BatchLookup {
                $existing = BatchLookup::query()->where('active_fingerprint', $fingerprint)->first();
                if ($existing instanceof BatchLookup) {
                    return $existing;
                }
                $batch = BatchLookup::query()->create([
                    'id' => (string) Str::uuid(), 'fingerprint' => $fingerprint, 'counter_id' => $counterId,
                    'active_fingerprint' => $fingerprint, 'attribution' => $attribution, 'lookback_days' => $lookback,
                    'time_tolerance_seconds' => $tolerance, 'selection_strategy' => $selectionStrategy,
                    'planned_date1' => now('UTC')->toDateString(), 'planned_date2' => now('UTC')->toDateString(), 'status' => 'queued',
                ]);

                foreach ($request->lookups as $lookup) {
                    $event = VisitEvent::query()->where([
                        'counter_id' => $counterId,
                        'client_id' => $lookup->clientId,
                        'occurred_at' => $lookup->occurredAt(),
                        'event_name' => $lookup->eventName,
                    ])->lockForUpdate()->first();

                    if ($event instanceof VisitEvent) {
                        $event->visits()->detach();
                        $event->update([
                            'batch_id' => $batch->id, 'external_id' => $lookup->key(), 'goal_id' => $lookup->goalId,
                            'primary_visit_id' => null, 'status' => 'pending', 'reason' => null,
                        ]);
                    } else {
                        $event = VisitEvent::query()->create([
                            'batch_id' => $batch->id, 'counter_id' => $counterId, 'external_id' => $lookup->key(),
                            'client_id' => $lookup->clientId, 'occurred_at' => $lookup->occurredAt(),
                            'event_name' => $lookup->eventName, 'goal_id' => $lookup->goalId,
                            'disable_goal_check' => false, 'status' => 'pending',
                        ]);
                    }
                }
                Visit::query()->doesntHave('events')->delete();
                $batch->setAttribute('_new_import', true);
                return $batch;
            });
        } catch (QueryException $exception) {
            $batch = BatchLookup::query()->where('active_fingerprint', $fingerprint)->first();
            if (! $batch instanceof BatchLookup) {
                throw $exception;
            }
        }

        if ($batch->getAttribute('_new_import') === true) {
            StartBatchJob::dispatch($batch->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
        }
        return $batch;
    }
}
