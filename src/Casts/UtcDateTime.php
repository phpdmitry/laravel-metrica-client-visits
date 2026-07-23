<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Treats timezone-less database timestamps as UTC instants.
 *
 * Package timestamps are written as UTC by the Logs API pipeline. Database
 * TIMESTAMP/DATETIME columns do not retain an offset, so the default Laravel
 * datetime cast would otherwise read them in the application's timezone.
 */
final class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : $this->toUtc($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : $this->toUtc($value)->format($model->getDateFormat());
    }

    private function toUtc(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        return CarbonImmutable::parse((string) $value, 'UTC');
    }
}
