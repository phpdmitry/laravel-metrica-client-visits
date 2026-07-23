<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\Support\TsvVisitParser;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;

final class TsvVisitParserTest extends TestCase
{
    #[Test]
    #[DataProvider('datetimeEnclosures')]
    public function it_parses_supported_datetime_enclosures(string $dateTime): void
    {
        $parser = new TsvVisitParser();
        $row = $this->row($parser, "\\'2025-11-19 13:22:49\\'");
        $counterTimezone = new DateTimeZone('Europe/Moscow');

        $visit = $parser->visit($row, $counterTimezone, new DateTimeZone('UTC'));

        self::assertInstanceOf(DateTimeImmutable::class, $visit->startedAt);
        self::assertSame('2025-11-19 13:22:49', $visit->startedAt->setTimezone($counterTimezone)->format('Y-m-d H:i:s'));
        self::assertSame('2025-11-19T10:22:49+00:00', $visit->startedAt->format(DATE_ATOM));
    }

    /** @return iterable<string, array{0: string}> */
    public static function datetimeEnclosures(): iterable
    {
        yield 'ordinary double quotes' => ['"2025-11-19 13:22:49"'];
        yield 'escaped double quotes' => ['\\"2025-11-19 13:22:49\\"'];
        yield 'escaped single quotes from Logs API' => ["\\'2025-11-19 13:22:49\\'"];
    }

    #[Test]
    public function it_keeps_standard_tsv_values_intact(): void
    {
        $parser = new TsvVisitParser();
        $row = $this->row(
            $parser,
            '"2025-11-19 13:22:49"',
            '[42,43]',
            '["2025-11-19 13:23:00","2025-11-19 13:24:00"]',
            'google',
            'https://example.test/path?utm_source=google&utm_medium=cpc',
            'https://site.test/?goal=42',
        );

        $visit = $parser->visit($row, new DateTimeZone('UTC'), new DateTimeZone('UTC'));

        self::assertSame([42, 43], $visit->goalIds);
        self::assertSame(['2025-11-19T13:23:00+00:00', '2025-11-19T13:24:00+00:00'], $visit->goalTimes);
        self::assertSame('google', $visit->utmSource);
        self::assertSame('https://example.test/path?utm_source=google&utm_medium=cpc', $visit->referrer);
        self::assertSame('https://site.test/?goal=42', $visit->startUrl);
    }

    /** @return array<string, string> */
    private function row(
        TsvVisitParser $parser,
        string $dateTime,
        string $goalIds = '[]',
        string $goalDateTimes = '[]',
        string $utmSource = '',
        string $referrer = '',
        string $startUrl = '',
    ): array
    {
        $header = implode("\t", [
            'ym:s:visitID', 'ym:s:dateTime', 'ym:s:visitDuration', 'ym:s:goalsID', 'ym:s:goalsDateTime',
            'ym:s:<attribution>TrafficSource', 'ym:s:<attribution>SourceEngine', 'ym:s:<attribution>UTMSource',
            'ym:s:<attribution>UTMMedium', 'ym:s:<attribution>UTMCampaign', 'ym:s:referer', 'ym:s:startURL',
        ]);
        $values = implode("\t", [
            '123', $dateTime, '30', $goalIds, $goalDateTimes, 'direct', '', $utmSource, '', '', $referrer, $startUrl,
        ]);

        return iterator_to_array($parser->rows($header . "\n" . $values . "\n"))[0];
    }
}
