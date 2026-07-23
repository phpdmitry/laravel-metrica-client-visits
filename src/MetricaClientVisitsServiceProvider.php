<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits;

use Illuminate\Support\ServiceProvider;
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
