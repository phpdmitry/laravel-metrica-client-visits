<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VisitMatch extends Model
{
    protected $table = 'metrica_visit_matches';
    protected $guarded = [];
    protected $casts = ['visit_started_at' => 'datetime', 'goal_confirmed' => 'boolean'];
    public function batch(): BelongsTo { return $this->belongsTo(BatchLookup::class, 'batch_id'); }
    public function event(): BelongsTo { return $this->belongsTo(StoredClientEvent::class, 'event_id'); }
}
