<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Commands;

use Illuminate\Console\Command;
use PhpDmitry\MetricaClientVisits\Models\LogRequest;

final class StuckRequestsCommand extends Command
{
    protected $signature = 'metrica-client-visits:stuck {--minutes=30 : Сколько минут считать запрос зависшим}';
    protected $description = 'Показывает незавершённые запросы Logs API без недавнего обновления.';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $requests = LogRequest::query()
            ->whereIn('status', ['planned', 'waiting_lock', 'creating', 'created', 'processing', 'processed', 'downloading', 'downloaded', 'cleanup_pending', 'creation_uncertain', 'creation_ambiguous'])
            ->where('updated_at', '<', $cutoff)
            ->get(['id', 'batch_id', 'request_id', 'status', 'updated_at', 'error_message']);
        if ($requests->isEmpty()) {
            $this->info('Зависших запросов нет.');
            return self::SUCCESS;
        }
        $this->table(['ID', 'Batch', 'Logs request', 'Статус', 'Обновлён', 'Ошибка'], $requests->map(fn ($request) => [$request->id, $request->batch_id, $request->request_id ?: '—', $request->status, $request->updated_at, $request->error_message ?: '—'])->all());
        return self::FAILURE;
    }
}
