<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpDmitry\MetricaClientVisits\Exceptions\LogsApiException;
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
    public function it_sends_log_request_creation_parameters_in_the_query_string(): void
    {
        Http::fake(['*' => Http::response(['log_request' => ['request_id' => 57588935]], 200)]);

        $response = $this->app->make(HttpLogsApiClient::class)->create(
            '12345678',
            '2026-04-21',
            '2026-04-28',
            ['ym:s:visitID', 'ym:s:dateTime'],
            'lastsign',
        );

        self::assertSame(57588935, $response['log_request']['request_id']);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api-metrika.yandex.net/management/v1/counter/12345678/logrequests?date1=2026-04-21&date2=2026-04-28&fields=ym%3As%3AvisitID%2Cym%3As%3AdateTime&source=visits&attribution=lastsign'
                && $request->body() === '[]'
                && $request->hasHeader('Authorization', 'OAuth test-token');
        });
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function apiErrorStatuses(): iterable
    {
        yield 'client error' => [422, 'Некорректный запрос Logs API'];
        yield 'server error' => [503, 'Logs API временно недоступен'];
    }

    #[Test]
    #[DataProvider('apiErrorStatuses')]
    public function it_preserves_api_error_messages_for_failed_log_request_creation(int $status, string $message): void
    {
        Http::fake(['*' => Http::response(['message' => $message], $status)]);

        try {
            $this->app->make(HttpLogsApiClient::class)->create('12345678', '2026-04-21', '2026-04-28', ['ym:s:visitID'], 'lastsign');
            self::fail('Ожидалось исключение LogsApiException.');
        } catch (LogsApiException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame($status, $exception->statusCode);
        }
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
