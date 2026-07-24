<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PhpDmitry\MetricaClientVisits\Data\VisitLookup;
use PhpDmitry\MetricaClientVisits\Support\ExportPeriodPlanner;

final class ExportPeriodPlannerTest extends TestCase
{
    #[Test]
    public function it_builds_and_splits_export_period(): void
    {
        $periods = (new ExportPeriodPlanner())->initialPeriods([
            new VisitLookup('1234567890123456789', 1_714_323_529),
            new VisitLookup('1234567890123456790', 1_714_409_929),
        ], 2, 120, 2);

        self::assertSame('2024-04-26', $periods[0]['date1']);
        self::assertSame('2024-04-29', $periods[array_key_last($periods)]['date2']);
        self::assertCount(2, $periods);
    }
}
