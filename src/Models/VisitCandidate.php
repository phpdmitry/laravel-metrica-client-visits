<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PhpDmitry\MetricaClientVisits\Casts\UtcDateTime;

final class VisitCandidate extends Model
{
    protected $table = 'metrica_visit_candidates';
    protected $guarded = [];
    protected $casts = ['started_at' => UtcDateTime::class, 'goal_ids' => 'array', 'goal_times' => 'array'];
    protected $appends = ['visit_started_at'];

    /** Совместимое с VisitMatch публичное имя времени начала визита. */
    public function getVisitStartedAtAttribute(): mixed
    {
        return $this->started_at;
    }

    public function event(): BelongsTo { return $this->belongsTo(StoredClientEvent::class, 'event_id'); }
}
