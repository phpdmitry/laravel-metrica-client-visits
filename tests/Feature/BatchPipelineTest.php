<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\ClientEventMatcher;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Data\BatchLookupRequest;
use PhpDmitry\MetricaClientVisits\Data\ClientEvent;
use PhpDmitry\MetricaClientVisits\Tests\Fakes\FakeLogsApiClient;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;

final class BatchPipelineTest extends TestCase
{
    #[Test]
    public function it_processes_a_batch_and_keeps_only_matching_visits(): void
    {
        $tsv = implode("\t", ['ym:s:visitID', 'ym:s:dateTime', 'ym:s:clientID', 'ym:s:pageViews', 'ym:s:visitDuration', 'ym:s:startURL', 'ym:s:referer', 'ym:s:goalsID', 'ym:s:goalsDateTime', 'ym:s:<attribution>TrafficSource', 'ym:s:<attribution>SourceEngine', 'ym:s:<attribution>UTMSource', 'ym:s:<attribution>UTMMedium', 'ym:s:<attribution>UTMCampaign']) . "\n"
            . implode("\t", ['123', '2026-04-28 17:32:09', '1234567890123456789', '1', '33', 'https://example.test', 'https://yandex.ru/', '[]', '[]', 'ad', '[3, Яндекс: Директ]', 'yandex', 'cpc', 'campaign']) . "\n";
        $api = new FakeLogsApiClient([$tsv]);
        $this->app->instance(LogsApiClient::class, $api);

        $batch = $this->app->make(ClientEventMatcher::class)->start(new BatchLookupRequest([
            new ClientEvent('deal-1', '1234567890123456789', 1_777_391_920),
            new ClientEvent('deal-2', '1234567890123456790', 1_777_391_920),
        ]));
        $batch->refresh();

        self::assertSame('completed_with_missing', $batch->status());
        self::assertSame(['evaluate', 'create', 'status', 'download', 'clean'], $api->calls);
        self::assertSame('ad', $batch->matches()->where('event_id', $batch->events()->where('external_id', 'deal-1')->value('id'))->value('source'));
        self::assertSame(2, $batch->matches()->count());
    }

    #[Test]
    public function it_uses_one_export_for_one_hundred_client_ids(): void
    {
        $header = implode("\t", ['ym:s:visitID', 'ym:s:dateTime', 'ym:s:clientID', 'ym:s:pageViews', 'ym:s:visitDuration', 'ym:s:startURL', 'ym:s:referer', 'ym:s:goalsID', 'ym:s:goalsDateTime', 'ym:s:<attribution>TrafficSource', 'ym:s:<attribution>SourceEngine', 'ym:s:<attribution>UTMSource', 'ym:s:<attribution>UTMMedium', 'ym:s:<attribution>UTMCampaign']);
        $events = [];
        $rows = [$header];
        for ($number = 0; $number < 100; $number++) {
            $clientId = '12345678901234567' . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $events[] = new ClientEvent("deal-{$number}", $clientId, 1_777_391_920);
            $rows[] = implode("\t", [(string) $number, '2026-04-28 17:32:09', $clientId, '1', '33', 'https://example.test', '', '[]', '[]', 'ad', '', 'yandex', 'cpc', 'campaign']);
        }
        $api = new FakeLogsApiClient([implode("\n", $rows) . "\n"]);
        $this->app->instance(LogsApiClient::class, $api);

        $batch = $this->app->make(ClientEventMatcher::class)->start(new BatchLookupRequest($events));
        $batch->refresh();

        self::assertSame('completed', $batch->status);
        self::assertSame(100, $batch->matches()->whereNotNull('visit_id')->count());
        self::assertSame(1, count(array_keys($api->calls, 'create', true)));
    }
}
