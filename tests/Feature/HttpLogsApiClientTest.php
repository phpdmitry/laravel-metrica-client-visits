<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\Services\HttpLogsApiClient;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;

final class HttpLogsApiClientTest extends TestCase
{
    #[Test]
    public function it_sends_logs_evaluation_with_oauth_header(): void
    {
        Http::fake(['*' => Http::response(['log_request_evaluation' => ['possible' => true]], 200)]);

        $response = $this->app->make(HttpLogsApiClient::class)->evaluate('12345678', '2026-04-21', '2026-04-28', ['ym:s:visitID']);

        self::assertTrue($response['log_request_evaluation']['possible']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api-metrika.yandex.net/management/v1/counter/12345678/logrequests/evaluate?date1=2026-04-21&date2=2026-04-28&fields=ym%3As%3AvisitID&source=visits'
                && $request->hasHeader('Authorization', 'OAuth test-token');
        });
    }

    #[Test]
    public function it_downloads_a_logs_part_to_a_temporary_stream(): void
    {
        Http::fake(['*' => Http::response("column\nvalue\n", 200)]);

        $stream = $this->app->make(HttpLogsApiClient::class)->download('12345678', '57588935', 0);

        self::assertIsResource($stream);
        self::assertSame("column\nvalue\n", stream_get_contents($stream));
        fclose($stream);
    }
}
