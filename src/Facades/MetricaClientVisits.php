<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Facades;

use Illuminate\Support\Facades\Facade;
use PhpDmitry\MetricaClientVisits\VisitImporter;

/** @method static \PhpDmitry\MetricaClientVisits\Models\BatchLookup start(\PhpDmitry\MetricaClientVisits\Data\VisitImportRequest $request) */
final class MetricaClientVisits extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VisitImporter::class;
    }
}
