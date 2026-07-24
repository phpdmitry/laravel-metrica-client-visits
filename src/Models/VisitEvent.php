<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PhpDmitry\MetricaClientVisits\Casts\UtcDateTime;

final class VisitEvent extends Model
{
    protected $table = 'metrica_visit_events';
    protected $guarded = [];
    protected $casts = ['occurred_at' => UtcDateTime::class];

    public function batch(): BelongsTo { return $this->belongsTo(BatchLookup::class, 'batch_id'); }
    public function visits(): BelongsToMany
    {
        return $this->belongsToMany(Visit::class, 'metrica_visit_event_visit', 'event_id', 'visit_id')
            ->withPivot('log_request_id')->withTimestamps()->orderBy('metrica_visits.started_at');
    }
    public function primaryVisit(): BelongsTo { return $this->belongsTo(Visit::class, 'primary_visit_id'); }
}
