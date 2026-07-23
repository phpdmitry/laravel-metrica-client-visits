<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpDmitry\MetricaClientVisits\Models\StoredClientEvent;
use PhpDmitry\MetricaClientVisits\Models\VisitCandidate;

final class VisitSelector
{
    /** @param Collection<int, VisitCandidate> $candidates @return array{candidate:?VisitCandidate,match_type:?string,confidence:?string,reason:?string,goal_confirmed:bool} */
    public function select(StoredClientEvent $event, Collection $candidates, int $lookbackDays, int $toleranceSeconds, ?int $defaultGoalId): array
    {
        $occurredAt = CarbonImmutable::parse($event->occurred_at)->utc();
        $goalId = $event->disable_goal_check ? null : ($event->goal_id ?? $defaultGoalId);
        $temporal = $this->temporalCandidate($candidates, $occurredAt, $lookbackDays, $toleranceSeconds);

        if ($goalId !== null) {
            $goalCandidate = $candidates
                ->filter(function (VisitCandidate $candidate) use ($goalId, $occurredAt, $toleranceSeconds): bool {
                    if (! in_array((int) $goalId, array_map('intval', $candidate->goal_ids ?? []), true)) {
                        return false;
                    }
                    foreach ($candidate->goal_times ?? [] as $goalTime) {
                        if (abs(CarbonImmutable::parse($goalTime)->utc()->diffInSeconds($occurredAt, false)) <= $toleranceSeconds) {
                            return true;
                        }
                    }
                    return $this->covers($candidate, $occurredAt, $toleranceSeconds);
                })
                ->sortByDesc('started_at')
                ->first();

            if ($goalCandidate instanceof VisitCandidate) {
                return ['candidate' => $goalCandidate, 'match_type' => 'goal_confirmed', 'confidence' => 'high', 'reason' => null, 'goal_confirmed' => true];
            }

            return ['candidate' => $temporal, 'match_type' => $temporal ? 'temporal_candidate' : null, 'confidence' => $temporal ? 'low' : null, 'reason' => 'goal_not_found', 'goal_confirmed' => false];
        }

        if (! $temporal instanceof VisitCandidate) {
            return ['candidate' => null, 'match_type' => null, 'confidence' => null, 'reason' => 'visit_not_found', 'goal_confirmed' => false];
        }

        return [
            'candidate' => $temporal,
            'match_type' => $this->covers($temporal, $occurredAt, $toleranceSeconds) ? 'visit_contains_event' : 'last_visit_before_event',
            'confidence' => 'medium', 'reason' => null, 'goal_confirmed' => false,
        ];
    }

    /** @param Collection<int, VisitCandidate> $candidates */
    private function temporalCandidate(Collection $candidates, CarbonImmutable $occurredAt, int $lookbackDays, int $toleranceSeconds): ?VisitCandidate
    {
        $covering = $candidates->filter(fn (VisitCandidate $candidate): bool => $this->covers($candidate, $occurredAt, $toleranceSeconds))->sortByDesc('started_at')->first();
        if ($covering instanceof VisitCandidate) {
            return $covering;
        }

        $oldest = $occurredAt->subDays($lookbackDays);
        return $candidates->filter(function (VisitCandidate $candidate) use ($occurredAt, $oldest): bool {
            $started = CarbonImmutable::parse($candidate->started_at)->utc();
            return $started <= $occurredAt && $started >= $oldest;
        })->sortByDesc('started_at')->first();
    }

    private function covers(VisitCandidate $candidate, CarbonImmutable $occurredAt, int $toleranceSeconds): bool
    {
        $started = CarbonImmutable::parse($candidate->started_at)->utc();
        $ended = $started->addSeconds((int) $candidate->duration_seconds);
        return $started <= $occurredAt->addSeconds($toleranceSeconds) && $ended >= $occurredAt->subSeconds($toleranceSeconds);
    }
}
