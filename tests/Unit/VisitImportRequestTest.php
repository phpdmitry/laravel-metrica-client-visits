<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PhpDmitry\MetricaClientVisits\Data\VisitImportRequest;
use PhpDmitry\MetricaClientVisits\Data\VisitLookup;

final class VisitImportRequestTest extends TestCase
{
    #[Test]
    public function it_validates_client_id_and_timestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VisitLookup('not-a-client-id', 1_714_000_000);
    }

    #[Test]
    public function it_rejects_empty_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VisitImportRequest([]);
    }
}
