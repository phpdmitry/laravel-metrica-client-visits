<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Services;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Exceptions\LogsApiException;

final class HttpLogsApiClient implements LogsApiClient
{
    public function __construct(private readonly Factory $http)
    {
    }

    public function evaluate(string $counterId, string $date1, string $date2, array $fields, string $source = 'visits'): array
    {
        return $this->json('get', $this->url($counterId, 'logrequests/evaluate'), [
            'date1' => $date1, 'date2' => $date2, 'fields' => implode(',', $fields), 'source' => $source,
        ], $counterId);
    }

    public function create(string $counterId, string $date1, string $date2, array $fields, string $attribution, string $source = 'visits'): array
    {
        $query = http_build_query([
            'date1' => $date1, 'date2' => $date2, 'fields' => implode(',', $fields),
            'source' => $source, 'attribution' => $attribution,
        ]);

        return $this->json('post', $this->url($counterId, 'logrequests') . '?' . $query, [], $counterId);
    }

    public function status(string $counterId, string $requestId): array
    {
        return $this->json('get', $this->url($counterId, "logrequest/{$requestId}"), [], $counterId, $requestId);
    }

    public function list(string $counterId): array
    {
        return $this->json('get', $this->url($counterId, 'logrequests'), [], $counterId);
    }

    public function download(string $counterId, string $requestId, int $partNumber)
    {
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Не удалось создать временный поток для части Logs API.');
        }
        $endpoint = "logrequest/{$requestId}/part/{$partNumber}/download";
        try {
            $response = $this->request()->sink($stream)->get($this->url($counterId, $endpoint));
            $this->throwIfFailed($response, $endpoint, $counterId, $requestId);
        } catch (ConnectionException $exception) {
            fclose($stream);
            throw $this->networkException($endpoint, $counterId, $requestId, $exception);
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
        rewind($stream);
        return $stream;
    }

    public function clean(string $counterId, string $requestId): void
    {
        $endpoint = "logrequest/{$requestId}/clean";
        try {
            $response = $this->request()->post($this->url($counterId, $endpoint));
            $this->throwIfFailed($response, $endpoint, $counterId, $requestId);
        } catch (ConnectionException $exception) {
            throw $this->networkException($endpoint, $counterId, $requestId, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $url, array $data = [], ?string $counterId = null, ?string $requestId = null): array
    {
        $counterId ??= $this->counterIdFromUrl($url);
        $endpoint = (string) parse_url($url, PHP_URL_PATH);
        try {
            $request = $this->request();
            $response = $method === 'get' ? $request->get($url, $data) : $request->post($url, $data);
            $this->throwIfFailed($response, $endpoint, $counterId, $requestId);
        } catch (ConnectionException $exception) {
            throw $this->networkException($endpoint, $counterId, $requestId, $exception);
        }
        $json = $response->json();

        if (! is_array($json)) {
            throw new LogsApiException('Logs API вернул некорректный JSON-ответ.', $endpoint, $counterId, $requestId);
        }

        return $json;
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $token = (string) config('metrica-client-visits.token');
        if ($token === '') {
            throw new RuntimeException('Не задан metrica-client-visits.token (YANDEX_METRIKA_TOKEN).');
        }

        return $this->http->acceptJson()
            ->withHeaders(['Authorization' => "OAuth {$token}"])
            ->connectTimeout((int) config('metrica-client-visits.http_connect_timeout_seconds', 10))
            ->timeout((int) config('metrica-client-visits.http_timeout_seconds', 90));
    }

    private function url(string $counterId, string $path): string
    {
        return "https://api-metrika.yandex.net/management/v1/counter/{$counterId}/{$path}";
    }

    private function throwIfFailed(Response $response, string $endpoint, string $counterId, ?string $requestId = null): void
    {
        try {
            $response->throw();
        } catch (RequestException $exception) {
            $body = $response->json();
            $message = is_array($body) ? (string) ($body['message'] ?? data_get($body, 'errors.0.message') ?? 'Ошибка Logs API.') : 'Ошибка Logs API.';
            $retryAfter = $response->header('Retry-After');
            throw new LogsApiException($message, $endpoint, $counterId, $requestId, $response->status(), is_numeric($retryAfter) ? (int) $retryAfter : null, $exception);
        }
    }

    private function networkException(string $endpoint, string $counterId, ?string $requestId, ConnectionException $exception): LogsApiException
    {
        return new LogsApiException('Сетевая ошибка при обращении к Logs API.', $endpoint, $counterId, $requestId, null, null, $exception);
    }

    private function counterIdFromUrl(string $url): string
    {
        preg_match('#/counter/(\d+)/#', $url, $matches);
        return $matches[1] ?? 'unknown';
    }
}
