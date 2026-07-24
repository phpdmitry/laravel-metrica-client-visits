<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Data\VisitImportRequest;
use PhpDmitry\MetricaClientVisits\Data\VisitLookup;
use PhpDmitry\MetricaClientVisits\Models\Visit;
use PhpDmitry\MetricaClientVisits\Models\VisitEvent;
use PhpDmitry\MetricaClientVisits\Tests\Fakes\FakeLogsApiClient;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;
use PhpDmitry\MetricaClientVisits\VisitImporter;

final class BatchPipelineTest extends TestCase
{
    #[Test]
    public function it_imports_one_hundred_clients_into_queryable_visits(): void
    {
        $lookups = [];
        $rows = [];
        for ($number = 0; $number < 100; $number++) {
            $clientId = '12345678901234567' . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $lookups[] = new VisitLookup($clientId, 1_777_391_920, 'Регистрация');
            $rows[] = [(string) $number, '2026-04-28 17:32:09', $clientId, 'source-' . $number];
        }
        $api = new FakeLogsApiClient([$this->tsv($rows)]);
        $this->app->instance(LogsApiClient::class, $api);

        $batch = $this->app->make(VisitImporter::class)->start(new VisitImportRequest($lookups));

        self::assertSame('completed', $batch->refresh()->status);
        self::assertCount(100, Visit::query()->whereIn('client_id', array_map(fn (VisitLookup $item) => $item->clientId, $lookups))->get());
        self::assertSame(1, count(array_keys($api->calls, 'create', true)));
        self::assertSame('source-0', Visit::query()->where('visit_id', '0')->value('source'));
    }

    #[Test]
    public function it_keeps_all_visits_and_a_primary_visit_for_each_named_event(): void
    {
        $api = new FakeLogsApiClient([$this->tsv([
            ['101', '2026-04-28 17:00:00', '1234567890123456789', 'first'],
            ['102', '2026-04-28 17:20:00', '1234567890123456789', 'middle'],
            ['103', '2026-04-28 17:32:09', '1234567890123456789', 'last'],
        ])]);
        $this->app->instance(LogsApiClient::class, $api);
        $this->app->make(VisitImporter::class)->start(new VisitImportRequest([
            new VisitLookup('1234567890123456789', 1_777_386_720, 'Регистрация'),
            new VisitLookup('1234567890123456789', 1_777_386_060, 'Заявка'),
        ]));

        $events = VisitEvent::query()->with(['visits', 'primaryVisit'])->orderBy('event_name')->get();
        self::assertCount(2, $events);
        self::assertSame(['101', '102', '103'], $events->first()->visits->pluck('visit_id')->all());
        self::assertSame('103', $events->firstWhere('event_name', 'Регистрация')->primaryVisit->visit_id);
        self::assertSame('102', $events->firstWhere('event_name', 'Заявка')->primaryVisit->visit_id);
    }

    #[Test]
    public function it_replaces_an_identical_event_and_removes_its_orphaned_visits(): void
    {
        $api = new FakeLogsApiClient();
        $api->exports = [[$this->tsv([['101', '2026-04-28 17:00:00', '1234567890123456789', 'old']])], [$this->tsv([['102', '2026-04-28 17:32:09', '1234567890123456789', 'new']])]];
        $this->app->instance(LogsApiClient::class, $api);
        $importer = $this->app->make(VisitImporter::class);
        $request = new VisitImportRequest([new VisitLookup('1234567890123456789', 1_777_391_920, 'Заявка')]);
        $importer->start($request);
        $importer->start($request);

        self::assertSame(1, VisitEvent::query()->count());
        self::assertSame(['102'], Visit::query()->pluck('visit_id')->all());
        self::assertSame('new', Visit::query()->sole()->source);
    }

    #[Test]
    public function it_keeps_a_shared_visit_when_only_one_event_is_reimported(): void
    {
        $api = new FakeLogsApiClient();
        $api->exports = [[$this->tsv([['101', '2026-04-28 17:00:00', '1234567890123456789', 'shared']])], [$this->tsv([['102', '2026-04-28 17:32:09', '1234567890123456789', 'new']])]];
        $this->app->instance(LogsApiClient::class, $api);
        $importer = $this->app->make(VisitImporter::class);
        $importer->start(new VisitImportRequest([
            new VisitLookup('1234567890123456789', 1_777_391_920, 'Регистрация'),
            new VisitLookup('1234567890123456789', 1_777_391_920, 'Заявка'),
        ]));
        $importer->start(new VisitImportRequest([new VisitLookup('1234567890123456789', 1_777_391_920, 'Регистрация')]));

        self::assertSame(['101', '102'], Visit::query()->orderBy('visit_id')->pluck('visit_id')->all());
        self::assertSame(['101'], VisitEvent::query()->where('event_name', 'Заявка')->sole()->visits()->pluck('metrica_visits.visit_id')->all());
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $visits */
    private function tsv(array $visits): string
    {
        $header = implode("\t", ['ym:s:visitID', 'ym:s:dateTime', 'ym:s:clientID', 'ym:s:pageViews', 'ym:s:visitDuration', 'ym:s:startURL', 'ym:s:referer', 'ym:s:goalsID', 'ym:s:goalsDateTime', 'ym:s:<attribution>TrafficSource', 'ym:s:<attribution>SourceEngine', 'ym:s:<attribution>UTMSource', 'ym:s:<attribution>UTMMedium', 'ym:s:<attribution>UTMCampaign']);
        $rows = array_map(static fn (array $visit): string => implode("\t", [$visit[0], $visit[1], $visit[2], '1', '33', 'https://example.test', '', '[]', '[]', $visit[3], '', 'yandex', 'cpc', 'campaign']), $visits);
        return $header . "\n" . implode("\n", $rows) . "\n";
    }
}
