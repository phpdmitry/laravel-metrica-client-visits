<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpDmitry\MetricaClientVisits\Models\BatchLookup;
use PhpDmitry\MetricaClientVisits\Jobs\Concerns\UsesMetricaQueuePolicy;
use PhpDmitry\MetricaClientVisits\Models\VisitMatch;
use PhpDmitry\MetricaClientVisits\Services\VisitSelector;

final class FinalizeBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use UsesMetricaQueuePolicy;

    public function __construct(public readonly string $batchId)
    {
        $this->configureQueueTimeout();
    }

    public function handle(VisitSelector $selector): void
    {
        $batch = BatchLookup::query()->with(['events.candidates', 'logRequests'])->findOrFail($this->batchId);
        if ($batch->isCompleted()) {
            return;
        }
        if ($batch->logRequests->contains(fn ($request): bool => in_array($request->status, ['planned', 'waiting_lock', 'creating', 'created', 'processing', 'processed', 'downloading'], true))) {
            return;
        }
        if ($batch->logRequests->contains(fn ($request): bool => $request->failure_stage !== null || in_array($request->status, ['failed', 'creation_uncertain', 'creation_ambiguous'], true))) {
            $batch->events()->where('status', 'pending')->update(['status' => 'failed', 'reason' => 'export_failed']);
            $batch->update(['status' => 'failed', 'error_message' => 'Хотя бы один запрос Logs API завершился ошибкой.', 'completed_at' => now()]);
            return;
        }

        foreach ($batch->events as $event) {
            $result = $selector->select($event, $event->candidates, (int) $batch->lookback_days, (int) $batch->time_tolerance_seconds, config('metrica-client-visits.default_goal_id') ? (int) config('metrica-client-visits.default_goal_id') : null);
            $candidate = $result['candidate'];
            $fields = ['batch_id' => $batch->id, 'candidate_id' => $candidate?->id, 'match_type' => $result['match_type'], 'confidence' => $result['confidence'], 'reason' => $result['reason'], 'goal_confirmed' => $result['goal_confirmed']];
            if ($candidate !== null) {
                $fields += [
                    'visit_id' => $candidate->visit_id, 'visit_started_at' => $candidate->started_at,
                    'duration_seconds' => $candidate->duration_seconds, 'source' => $candidate->source,
                    'source_detail' => $candidate->source_detail, 'utm_source' => $candidate->utm_source,
                    'utm_medium' => $candidate->utm_medium, 'utm_campaign' => $candidate->utm_campaign,
                    'referrer' => $candidate->referrer, 'start_url' => $candidate->start_url,
                ];
            }
            VisitMatch::query()->updateOrCreate(['event_id' => $event->id], $fields);
            $event->update(['status' => $result['reason'] === 'goal_not_found' ? 'goal_not_found' : ($candidate === null ? 'missing' : 'matched'), 'reason' => $result['reason']]);
        }
        $hasMissing = $batch->events()->whereIn('status', ['missing', 'goal_not_found'])->exists();
        $batch->update(['status' => $hasMissing ? 'completed_with_missing' : 'completed', 'completed_at' => now()]);
    }

    public function failed(\Throwable $exception): void
    {
        BatchLookup::query()->whereKey($this->batchId)->whereNotIn('status', ['completed', 'completed_with_missing'])->update([
            'status' => 'failed', 'error_message' => 'Не удалось завершить сопоставление: ' . $exception->getMessage(), 'completed_at' => now(),
        ]);
    }
}
