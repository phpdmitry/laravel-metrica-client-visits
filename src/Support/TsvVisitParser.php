<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Support;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use PhpDmitry\MetricaClientVisits\Data\VisitMatchData;

final class TsvVisitParser
{
    /** @return Generator<int, array<string, string>> */
    public function rows(mixed $tsv): Generator
    {
        $mustClose = false;
        if (is_resource($tsv)) {
            $stream = $tsv;
            rewind($stream);
        } else {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                return;
            }
            fwrite($stream, (string) $tsv);
            rewind($stream);
            $mustClose = true;
        }

        $header = fgetcsv($stream, separator: "\t", enclosure: '"', escape: "\\");
        if (! is_array($header)) {
            if ($mustClose) {
                fclose($stream);
            }
            return;
        }

        while (($row = fgetcsv($stream, separator: "\t", enclosure: '"', escape: "\\")) !== false) {
            if ($row === [null] || count($row) !== count($header)) {
                continue;
            }
            yield array_combine($header, $row) ?: [];
        }
        if ($mustClose) {
            fclose($stream);
        }
    }

    /** @param array<string, string> $row */
    public function visit(array $row, DateTimeZone $counterTimezone, DateTimeZone $goalTimezone): VisitMatchData
    {
        $startedAt = $this->dateTime((string) ($row['ym:s:dateTime'] ?? ''), $counterTimezone);
        $goalIds = $this->list($row['ym:s:goalsID'] ?? '');
        $goalTimes = array_map(
            fn (string $time): string => $this->dateTime($time, $goalTimezone)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            $this->list($row['ym:s:goalsDateTime'] ?? ''),
        );

        return new VisitMatchData(
            visitId: (string) ($row['ym:s:visitID'] ?? ''),
            startedAt: $startedAt->setTimezone(new DateTimeZone('UTC')),
            durationSeconds: max(0, (int) ($row['ym:s:visitDuration'] ?? 0)),
            source: $this->nullable($row['ym:s:<attribution>TrafficSource'] ?? null),
            sourceDetail: $this->nullable($row['ym:s:<attribution>SourceEngine'] ?? null),
            utmSource: $this->nullable($row['ym:s:<attribution>UTMSource'] ?? null),
            utmMedium: $this->nullable($row['ym:s:<attribution>UTMMedium'] ?? null),
            utmCampaign: $this->nullable($row['ym:s:<attribution>UTMCampaign'] ?? null),
            referrer: $this->nullable($row['ym:s:referer'] ?? null),
            startUrl: $this->nullable($row['ym:s:startURL'] ?? null),
            goalIds: array_map('intval', $goalIds),
            goalTimes: $goalTimes,
        );
    }

    private function dateTime(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($this->unwrapDateTime($value), $timezone);
    }

    private function unwrapDateTime(string $value): string
    {
        foreach (['"', '\\"', "\\'"] as $enclosure) {
            if (str_starts_with($value, $enclosure) && str_ends_with($value, $enclosure)) {
                return substr($value, strlen($enclosure), -strlen($enclosure));
            }
        }

        return $value;
    }

    /** @return list<string> */
    private function list(string $value): array
    {
        $value = trim($value);
        if ($value === '' || $value === '[]') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_map('strval', $decoded));
        }

        return array_values(array_filter(array_map('trim', explode(',', trim($value, '[]'))), static fn (string $item): bool => $item !== ''));
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
