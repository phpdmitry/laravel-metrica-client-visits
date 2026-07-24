<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PhpDmitry\MetricaClientVisits\Casts\UtcDateTime;

final class Visit extends Model
{
    protected $table = 'metrica_visits';
    protected $guarded = [];
    protected $casts = ['started_at' => UtcDateTime::class, 'goal_ids' => 'array', 'goal_times' => 'array'];

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(VisitEvent::class, 'metrica_visit_event_visit', 'visit_id', 'event_id')
            ->withTimestamps();
    }
}
