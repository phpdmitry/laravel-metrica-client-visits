<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use PhpDmitry\MetricaClientVisits\Commands\BatchStatusCommand;
use PhpDmitry\MetricaClientVisits\Commands\CleanPendingCommand;
use PhpDmitry\MetricaClientVisits\Commands\StuckRequestsCommand;
use PhpDmitry\MetricaClientVisits\Contracts\LogsApiClient;
use PhpDmitry\MetricaClientVisits\Services\HttpLogsApiClient;

final class MetricaClientVisitsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/metrica-client-visits.php', 'metrica-client-visits');

        $this->app->singleton(LogsApiClient::class, HttpLogsApiClient::class);
        $this->app->singleton(ClientEventMatcher::class);
        $this->app->alias(ClientEventMatcher::class, 'metrica-client-visits');
    }

    public function boot(): void
    {
        RateLimiter::for('metrica-client-visits', function (object $job): Limit {
            $counterId = method_exists($job, 'counterId') ? $job->counterId() : 'unknown';
            return Limit::perMinute((int) config('metrica-client-visits.api_requests_per_minute_per_counter', 30))
                ->by("metrica-client-visits:counter:{$counterId}");
        });

        $this->publishes([
            __DIR__ . '/../config/metrica-client-visits.php' => config_path('metrica-client-visits.php'),
        ], 'metrica-client-visits-config');

        if (! class_exists('CreateMetricaVisitBatchesTable')) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([BatchStatusCommand::class, CleanPendingCommand::class, StuckRequestsCommand::class]);
        }
    }
}
