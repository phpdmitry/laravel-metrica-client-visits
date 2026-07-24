<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpDmitry\MetricaClientVisits\Models\Visit;
use PhpDmitry\MetricaClientVisits\Models\VisitEvent;

final class VisitSelector
{
    /** @param Collection<int, Visit> $candidates @return array{candidate:?Visit,match_type:?string,confidence:?string,reason:?string,goal_confirmed:bool} */
    public function select(VisitEvent $event, Collection $candidates, int $lookbackDays, int $toleranceSeconds, ?int $defaultGoalId, string $strategy = 'last'): array
    {
        if (! in_array($strategy, ['last', 'first'], true)) {
            throw new \InvalidArgumentException('Стратегия выбора должна быть last или first.');
        }
        $occurredAt = CarbonImmutable::parse($event->occurred_at)->utc();
        $goalId = $event->goal_id ?? $defaultGoalId;
        $temporal = $this->temporalCandidate($candidates, $occurredAt, $lookbackDays, $toleranceSeconds, $strategy);

        if ($goalId !== null) {
            $goalCandidate = $candidates
                ->filter(function (Visit $candidate) use ($goalId, $occurredAt, $toleranceSeconds): bool {
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
                ->pipe(fn (Collection $matches): ?Visit => $this->choose($matches, $strategy));

            if ($goalCandidate instanceof Visit) {
                return ['candidate' => $goalCandidate, 'match_type' => 'goal_confirmed', 'confidence' => 'high', 'reason' => null, 'goal_confirmed' => true];
            }

            return ['candidate' => $temporal, 'match_type' => $temporal ? 'temporal_candidate' : null, 'confidence' => $temporal ? 'low' : null, 'reason' => 'goal_not_found', 'goal_confirmed' => false];
        }

        if (! $temporal instanceof Visit) {
            return ['candidate' => null, 'match_type' => null, 'confidence' => null, 'reason' => 'visit_not_found', 'goal_confirmed' => false];
        }

        return [
            'candidate' => $temporal,
            'match_type' => $this->covers($temporal, $occurredAt, $toleranceSeconds) ? 'visit_contains_event' : 'last_visit_before_event',
            'confidence' => 'medium', 'reason' => null, 'goal_confirmed' => false,
        ];
    }

    /** @param Collection<int, Visit> $candidates */
    private function temporalCandidate(Collection $candidates, CarbonImmutable $occurredAt, int $lookbackDays, int $toleranceSeconds, string $strategy): ?Visit
    {
        $covering = $this->choose($candidates->filter(fn (Visit $candidate): bool => $this->covers($candidate, $occurredAt, $toleranceSeconds)), $strategy);
        if ($covering instanceof Visit) {
            return $covering;
        }

        $oldest = $occurredAt->subDays($lookbackDays);
        return $this->choose($candidates->filter(function (Visit $candidate) use ($occurredAt, $oldest): bool {
            $started = CarbonImmutable::parse($candidate->started_at)->utc();
            return $started <= $occurredAt && $started >= $oldest;
        }), $strategy);
    }

    /** @param Collection<int, Visit> $candidates */
    private function choose(Collection $candidates, string $strategy): ?Visit
    {
        return ($strategy === 'first' ? $candidates->sortBy('started_at') : $candidates->sortByDesc('started_at'))->first();
    }

    private function covers(Visit $candidate, CarbonImmutable $occurredAt, int $toleranceSeconds): bool
    {
        $started = CarbonImmutable::parse($candidate->started_at)->utc();
        $ended = $started->addSeconds((int) $candidate->duration_seconds);
        return $started <= $occurredAt->addSeconds($toleranceSeconds) && $ended >= $occurredAt->subSeconds($toleranceSeconds);
    }
}
