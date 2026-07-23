<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Support;

final class LogsFields
{
    /** @return list<string> */
    public static function visits(): array
    {
        return [
            'ym:s:visitID', 'ym:s:dateTime', 'ym:s:clientID', 'ym:s:pageViews', 'ym:s:visitDuration',
            'ym:s:startURL', 'ym:s:referer', 'ym:s:goalsID', 'ym:s:goalsDateTime',
            'ym:s:<attribution>TrafficSource', 'ym:s:<attribution>SourceEngine',
            'ym:s:<attribution>UTMSource', 'ym:s:<attribution>UTMMedium', 'ym:s:<attribution>UTMCampaign',
        ];
    }
}
