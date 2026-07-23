<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StoredClientEvent extends Model
{
    protected $table = 'metrica_visit_events';
    protected $guarded = [];
    protected $casts = ['occurred_at' => 'datetime', 'disable_goal_check' => 'boolean'];
    public function batch(): BelongsTo { return $this->belongsTo(BatchLookup::class, 'batch_id'); }
    public function candidates(): HasMany { return $this->hasMany(VisitCandidate::class, 'event_id'); }
    public function match(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(VisitMatch::class, 'event_id'); }
}
