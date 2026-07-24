<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Data\VisitImportRequest;
use PhpDmitry\MetricaClientVisits\Data\VisitLookup;
use PhpDmitry\MetricaClientVisits\Jobs\StartBatchJob;
use PhpDmitry\MetricaClientVisits\Models\VisitEvent;
use PhpDmitry\MetricaClientVisits\Support\ExportPeriodPlanner;
use PhpDmitry\MetricaClientVisits\Tests\Fakes\FakeLogsApiClient;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;
use PhpDmitry\MetricaClientVisits\VisitImporter;

final class OccurredAtTimezoneTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('applicationTimezones')]
    public function it_preserves_event_timestamp_when_a_batch_is_created_and_started(string $timezone): void
    {
        $this->useApplicationTimezone($timezone);
        Queue::fake();
        $api = new FakeLogsApiClient();
        $this->app->instance(LogsApiClient::class, $api);
        $inputTimestamp = 1_763_547_720;

        $batch = $this->app->make(VisitImporter::class)->start(new VisitImportRequest([
            new VisitLookup('1763547719182326239', $inputTimestamp, 'timezone-event-' . $timezone),
        ]));

        $stored = VisitEvent::query()->where('batch_id', $batch->id)->sole();
        self::assertSame($inputTimestamp, $stored->occurred_at->utc()->timestamp);

        (new StartBatchJob($batch->id))->handle($api, $this->app->make(ExportPeriodPlanner::class));

        $stored = VisitEvent::query()->findOrFail($stored->id);
        self::assertSame($inputTimestamp, $stored->occurred_at->utc()->timestamp);
    }

    /** @return iterable<string, array{string}> */
    public static function applicationTimezones(): iterable
    {
        yield 'Moscow application timezone' => ['Europe/Moscow'];
        yield 'UTC application timezone' => ['UTC'];
    }

    #[Test]
    public function it_selects_a_moscow_visit_for_a_moscow_event_when_the_application_timezone_is_moscow(): void
    {
        $this->useApplicationTimezone('Europe/Moscow');
        $inputTimestamp = 1_763_547_720; // 2025-11-19 10:22:00 UTC / 13:22:00 MSK
        $api = new FakeLogsApiClient([$this->tsv('2025-11-19 13:21:55', '54')]);
        $this->app->instance(LogsApiClient::class, $api);

        $batch = $this->app->make(VisitImporter::class)->start(new VisitImportRequest([
            new VisitLookup('1763547719182326239', $inputTimestamp, 'timezone-match'),
        ]));
        $batch->refresh();
        $event = $batch->events()->with(['visits', 'primaryVisit'])->sole();
        $candidate = $event->visits->sole();

        self::assertSame('completed', $batch->status);
        self::assertSame($inputTimestamp, $event->occurred_at->utc()->timestamp);
        self::assertSame($inputTimestamp - 5, $candidate->started_at->utc()->timestamp);
        self::assertSame($candidate->id, $event->primaryVisit->id);
        self::assertSame('matched', $event->status);
    }

    private function useApplicationTimezone(string $timezone): void
    {
        config()->set('app.timezone', $timezone);
        date_default_timezone_set($timezone);
    }

    private function tsv(string $startedAt, string $duration): string
    {
        $header = implode("\t", ['ym:s:visitID', 'ym:s:dateTime', 'ym:s:clientID', 'ym:s:pageViews', 'ym:s:visitDuration', 'ym:s:startURL', 'ym:s:referer', 'ym:s:goalsID', 'ym:s:goalsDateTime', 'ym:s:<attribution>TrafficSource', 'ym:s:<attribution>SourceEngine', 'ym:s:<attribution>UTMSource', 'ym:s:<attribution>UTMMedium', 'ym:s:<attribution>UTMCampaign']);
        $row = implode("\t", ['timezone-visit', $startedAt, '1763547719182326239', '1', $duration, 'https://example.test', '', '[]', '[]', 'direct', '', '', '', '']);

        return $header . "\n" . $row . "\n";
    }
}
