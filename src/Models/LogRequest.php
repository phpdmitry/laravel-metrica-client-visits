<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LogRequest extends Model
{
    protected $table = 'metrica_visit_log_requests';
    protected $guarded = [];
    protected $casts = ['date1' => 'date', 'date2' => 'date', 'parts' => 'array', 'cleaned_at' => 'datetime'];
    public function batch(): BelongsTo { return $this->belongsTo(BatchLookup::class, 'batch_id'); }
}
