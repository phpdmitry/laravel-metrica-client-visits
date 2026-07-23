<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Exceptions\LogsApiException;
use PhpDmitry\MetricaClientVisits\Jobs\CreateLogRequestJob;
use PhpDmitry\MetricaClientVisits\Jobs\DownloadLogRequestJob;
use PhpDmitry\MetricaClientVisits\Models\BatchLookup;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;
use PhpDmitry\MetricaClientVisits\Support\LogsFields;
use PhpDmitry\MetricaClientVisits\Tests\Fakes\FakeLogsApiClient;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;

final class ResilienceTest extends TestCase
{
    #[Test]
    public function it_exposes_retry_after_for_a_rate_limited_logs_response(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Too many requests'], 429, ['Retry-After' => '47'])]);

        try {
            $this->app->make(\PhpDmitry\MetricaClientVisits\Services\HttpLogsApiClient::class)->evaluate('12345678', '2026-04-21', '2026-04-28', ['ym:s:visitID']);
            self::fail('Ожидалось исключение LogsApiException.');
        } catch (LogsApiException $exception) {
            self::assertTrue($exception->isRateLimited());
            self::assertSame(47, $exception->retryAfterSeconds);
            self::assertSame('12345678', $exception->counterId);
        }
    }

    #[Test]
    public function it_uses_timeout_and_backoff_that_are_safe_for_database_queue(): void
    {
        $job = new CreateLogRequestJob(1);

        self::assertSame(90, config('metrica-client-visits.http_timeout_seconds'));
        self::assertSame(110, $job->timeout);
        self::assertSame(130, config('metrica-client-visits.queue_retry_after_seconds'));
        self::assertSame([15, 30, 60, 120, 300], $job->backoff());
        self::assertCount(1, $job->middleware());
    }

    #[Test]
    public function it_reconciles_a_creation_after_an_uncertain_post_without_second_create(): void
    {
        $batch = $this->batch();
        $lock = Cache::store('array')->lock('metrica-client-visits:test-creation', 3600);
        self::assertTrue($lock->get());
        $request = $batch->logRequests()->create([
            'date1' => '2026-04-21', 'date2' => '2026-04-28', 'status' => 'creating',
            'lock_key' => 'metrica-client-visits:test-creation', 'lock_owner' => $lock->owner(), 'creation_token' => '5fd5bff1-4b1a-4f36-aef9-0ab4dc6d5c10', 'create_attempted_at' => now(),
        ]);
        $api = new FakeLogsApiClient();
        $api->requests = [[
            'request_id' => 777, 'date1' => '2026-04-21', 'date2' => '2026-04-28', 'source' => 'visits',
            'attribution' => 'LAST', 'fields' => LogsFields::visits(), 'status' => 'created',
        ]];
        $this->app->instance(LogsApiClient::class, $api);

        (new CreateLogRequestJob($request->id))->handle($api);
        $request->refresh();

        self::assertSame(777, $request->request_id);
        self::assertContains('list', $api->calls);
        self::assertNotContains('create', $api->calls);
    }

    #[Test]
    public function it_queues_cleanup_when_download_fails(): void
    {
        $batch = $this->batch();
        $request = $batch->logRequests()->create(['request_id' => 777, 'date1' => '2026-04-21', 'date2' => '2026-04-28', 'status' => 'processed', 'parts' => [['part_number' => 0]]]);
        $api = new FakeLogsApiClient();
        $api->downloadException = new \RuntimeException('Сбой скачивания.');
        $this->app->instance(LogsApiClient::class, $api);

        (new DownloadLogRequestJob($request->id))->failed($api->downloadException);
        $request->refresh();

        self::assertSame('cleaned', $request->status);
        self::assertSame('download', $request->failure_stage);
        self::assertContains('clean', $api->calls);
    }

    private function batch(): BatchLookup
    {
        return BatchLookup::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'fingerprint' => hash('sha256', uniqid('', true)),
            'counter_id' => 12345678, 'attribution' => 'last', 'lookback_days' => 30, 'time_tolerance_seconds' => 120,
            'planned_date1' => '2026-04-21', 'planned_date2' => '2026-04-28', 'status' => 'exporting',
        ]);
    }
}
