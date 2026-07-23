<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Facades;

use Illuminate\Support\Facades\Facade;
use PhpDmitry\MetricaClientVisits\ClientEventMatcher;

/** @method static \PhpDmitry\MetricaClientVisits\Models\BatchLookup start(\PhpDmitry\MetricaClientVisits\Data\BatchLookupRequest $request) */
final class MetricaClientVisits extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ClientEventMatcher::class;
    }
}
