<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BatchLookup extends Model
{
    use HasUuids;

    protected $table = 'metrica_visit_batches';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['planned_date1' => 'date', 'planned_date2' => 'date', 'completed_at' => 'datetime'];

    public function events(): HasMany { return $this->hasMany(StoredClientEvent::class, 'batch_id'); }
    public function logRequests(): HasMany { return $this->hasMany(LogRequest::class, 'batch_id'); }

    /** @return HasMany<VisitMatch, $this> */
    public function matches(): HasMany { return $this->hasMany(VisitMatch::class, 'batch_id'); }
    public function status(): string { return $this->status; }
    public function isCompleted(): bool { return in_array($this->status, ['completed', 'completed_with_missing', 'failed'], true); }
    public function missingEvents(): HasMany { return $this->events()->whereIn('status', ['missing', 'goal_not_found']); }
    public function failedEvents(): HasMany { return $this->events()->where('status', 'failed'); }
}
