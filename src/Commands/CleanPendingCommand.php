<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Commands;

use Illuminate\Console\Command;
use PhpDmitry\MetricaClientVisits\Jobs\CleanupLogRequestJob;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;

final class CleanPendingCommand extends Command
{
    protected $signature = 'metrica-client-visits:clean-pending {--batch= : Только один batch UUID}';
    protected $description = 'Повторно ставит в очередь очистку временных Logs API выгрузок.';

    public function handle(): int
    {
        $query = LogRequest::query()->where('status', 'cleanup_pending');
        if ($batch = $this->option('batch')) {
            $query->where('batch_id', $batch);
        }
        $requests = $query->get();
        foreach ($requests as $request) {
            CleanupLogRequestJob::dispatch($request->id)->onQueue((string) config('metrica-client-visits.queue', 'default'));
        }
        $this->info("В очередь добавлено очисток: {$requests->count()}.");
        return self::SUCCESS;
    }
}
