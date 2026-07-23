<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PhpDmitry\MetricaClientVisits\ClientEventMatcher;
use PhpDmitry\MetricaClientVisits\Data\BatchLookupRequest;
use PhpDmitry\MetricaClientVisits\Data\ClientEvent;
use PhpDmitry\MetricaClientVisits\Jobs\CreateLogRequestJob;
use PhpDmitry\MetricaClientVisits\Jobs\StartBatchJob;
use PhpDmitry\MetricaClientVisits\Support\ExportPeriodPlanner;
use PhpDmitry\MetricaClientVisits\Tests\Fakes\FakeLogsApiClient;
use PhpDmitry\MetricaClientVisits\Tests\TestCase;

final class DatabaseDriversTest extends TestCase
{
    #[Test]
    public function it_works_with_database_queue_and_cache_without_redis(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id(); $table->string('queue')->index(); $table->longText('payload'); $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable(); $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary(); $table->mediumText('value'); $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary(); $table->string('owner'); $table->integer('expiration');
        });
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
        $this->app['config']->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache', 'connection' => 'testing', 'lock_connection' => 'testing']);
        $this->app['config']->set('metrica-client-visits.cache_store', 'database');

        $batch = $this->app->make(ClientEventMatcher::class)->start(new BatchLookupRequest([new ClientEvent('deal-1', '1234567890123456789', 1_777_391_920)]));
        self::assertDatabaseCount('jobs', 1);

        $api = new FakeLogsApiClient([]);
        (new StartBatchJob($batch->id))->handle($api, new ExportPeriodPlanner());
        $request = $batch->logRequests()->firstOrFail();
        (new CreateLogRequestJob($request->id))->handle($api);
        $request->refresh();

        self::assertSame('created', $request->status);
        self::assertNotNull($request->lock_key);
        self::assertFalse(Cache::store('database')->lock($request->lock_key, 60)->get());
    }
}
