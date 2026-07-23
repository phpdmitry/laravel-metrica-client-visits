<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PhpDmitry\MetricaClientVisits\Data\ClientEvent;

final class ExportPeriodPlanner
{
    /** @param list<ClientEvent> $events @return list<array{date1:string,date2:string}> */
    public function initialPeriods(array $events, int $lookbackDays, int $toleranceSeconds, int $maxDays): array
    {
        $zone = new DateTimeZone('UTC');
        $times = array_map(static fn (ClientEvent $event): DateTimeImmutable => $event->occurredAt(), $events);
        usort($times, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        $from = $times[0]->sub(new DateInterval("P{$lookbackDays}D"));
        $to = $times[array_key_last($times)]->add(new DateInterval("PT{$toleranceSeconds}S"));

        return $this->split($from->setTimezone($zone), $to->setTimezone($zone), $maxDays);
    }

    /** @return list<array{date1:string,date2:string}> */
    public function split(DateTimeImmutable $from, DateTimeImmutable $to, int $maxDays): array
    {
        $maxDays = max(1, $maxDays);
        $cursor = $from->setTime(0, 0);
        $last = $to->setTime(0, 0);
        $periods = [];

        while ($cursor <= $last) {
            $end = $cursor->add(new DateInterval('P' . ($maxDays - 1) . 'D'));
            if ($end > $last) {
                $end = $last;
            }
            $periods[] = ['date1' => $cursor->format('Y-m-d'), 'date2' => $end->format('Y-m-d')];
            $cursor = $end->add(new DateInterval('P1D'));
        }

        return $periods;
    }
}
