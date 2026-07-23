<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\Models\StoredClientEvent;
use PhpDmitry\MetricaClientVisits\Models\VisitCandidate;
use PhpDmitry\MetricaClientVisits\Services\VisitSelector;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;

final class VisitSelectorTest extends TestCase
{
    #[Test]
    public function it_returns_temporal_candidate_when_goal_was_not_found(): void
    {
        $event = new StoredClientEvent(['occurred_at' => CarbonImmutable::parse('2026-04-28 14:32:00 UTC'), 'goal_id' => 42, 'disable_goal_check' => false]);
        $candidate = new VisitCandidate(['id' => 1, 'started_at' => CarbonImmutable::parse('2026-04-28 14:31:30 UTC'), 'duration_seconds' => 120, 'goal_ids' => [41], 'goal_times' => []]);

        $result = (new VisitSelector())->select($event, new Collection([$candidate]), 30, 120, null);

        self::assertSame('goal_not_found', $result['reason']);
        self::assertSame($candidate, $result['candidate']);
        self::assertFalse($result['goal_confirmed']);
    }

    #[Test]
    public function it_confirms_matching_goal_near_event_time(): void
    {
        $event = new StoredClientEvent(['occurred_at' => CarbonImmutable::parse('2026-04-28 14:32:00 UTC'), 'goal_id' => 42, 'disable_goal_check' => false]);
        $candidate = new VisitCandidate(['id' => 1, 'started_at' => CarbonImmutable::parse('2026-04-28 14:31:30 UTC'), 'duration_seconds' => 120, 'goal_ids' => [42], 'goal_times' => ['2026-04-28T14:32:10+00:00']]);

        $result = (new VisitSelector())->select($event, new Collection([$candidate]), 30, 120, null);

        self::assertSame('goal_confirmed', $result['match_type']);
        self::assertTrue($result['goal_confirmed']);
    }

    #[Test]
    public function it_can_optionally_select_the_first_matching_visit(): void
    {
        $event = new StoredClientEvent(['occurred_at' => CarbonImmutable::parse('2026-04-28 14:32:00 UTC'), 'disable_goal_check' => true]);
        $early = new VisitCandidate(['id' => 1, 'started_at' => CarbonImmutable::parse('2026-04-28 14:00:00 UTC'), 'duration_seconds' => 3_600]);
        $late = new VisitCandidate(['id' => 2, 'started_at' => CarbonImmutable::parse('2026-04-28 14:30:00 UTC'), 'duration_seconds' => 30]);

        $result = (new VisitSelector())->select($event, new Collection([$early, $late]), 30, 120, null, 'first');

        self::assertSame($early->id, $result['candidate']->id);
    }
}
