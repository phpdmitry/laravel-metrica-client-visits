<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Services;

use Illuminate\Http\Client\Factory;
use RuntimeException;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;

final class HttpLogsApiClient implements LogsApiClient
{
    public function __construct(private readonly Factory $http)
    {
    }

    public function evaluate(string $counterId, string $date1, string $date2, array $fields, string $source = 'visits'): array
    {
        return $this->json('get', $this->url($counterId, 'logrequests/evaluate'), [
            'date1' => $date1, 'date2' => $date2, 'fields' => implode(',', $fields), 'source' => $source,
        ]);
    }

    public function create(string $counterId, string $date1, string $date2, array $fields, string $attribution, string $source = 'visits'): array
    {
        return $this->json('post', $this->url($counterId, 'logrequests'), [
            'date1' => $date1, 'date2' => $date2, 'fields' => implode(',', $fields),
            'source' => $source, 'attribution' => $attribution,
        ]);
    }

    public function status(string $counterId, string $requestId): array
    {
        return $this->json('get', $this->url($counterId, "logrequest/{$requestId}"));
    }

    public function download(string $counterId, string $requestId, int $partNumber)
    {
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Не удалось создать временный поток для части Logs API.');
        }
        $response = $this->request()->sink($stream)->get($this->url($counterId, "logrequest/{$requestId}/part/{$partNumber}/download"));
        $response->throw();
        rewind($stream);
        return $stream;
    }

    public function clean(string $counterId, string $requestId): void
    {
        $this->request()->post($this->url($counterId, "logrequest/{$requestId}/clean"))->throw();
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $url, array $data = []): array
    {
        $request = $this->request();
        $response = $method === 'get' ? $request->get($url, $data) : $request->post($url, $data);
        $response->throw();
        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('Logs API вернул некорректный JSON-ответ.');
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
            ->timeout((int) config('metrica-client-visits.http_timeout_seconds', 120));
    }

    private function url(string $counterId, string $path): string
    {
        return "https://api-metrika.yandex.net/management/v1/counter/{$counterId}/{$path}";
    }
}
