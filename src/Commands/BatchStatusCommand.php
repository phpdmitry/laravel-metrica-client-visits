<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Commands;

use Illuminate\Console\Command;
use PhpDmitry\MetricaClientVisits\Models\BatchLookup;

final class BatchStatusCommand extends Command
{
    protected $signature = 'metrica-client-visits:status {batch : UUID batch-поиска}';
    protected $description = 'Показывает состояние batch-поиска визитов Метрики.';

    public function handle(): int
    {
        $batch = BatchLookup::query()->withCount(['events', 'matches'])->with('logRequests:id,batch_id,request_id,status,date1,date2,size')->find($this->argument('batch'));
        if ($batch === null) {
            $this->error('Batch не найден.');
            return self::FAILURE;
        }
        $this->table(['Поле', 'Значение'], [
            ['ID', $batch->id], ['Статус', $batch->status], ['Событий', $batch->events_count], ['Результатов', $batch->matches_count],
            ['Период', $batch->planned_date1 . ' — ' . $batch->planned_date2], ['Ошибка', $batch->error_message ?: '—'],
        ]);
        $this->table(['Request ID', 'Период', 'Статус', 'Размер'], $batch->logRequests->map(fn ($request) => [$request->request_id ?: '—', $request->date1 . ' — ' . $request->date2, $request->status, $request->size ?: '—'])->all());
        return self::SUCCESS;
    }
}
