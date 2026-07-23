<?php

declare(strict_types=1);

namespace PhpDmitry\MetricaClientVisits\Support;

use InvalidArgumentException;

final class Attribution
{
    public const ALLOWED = ['cross_device_first', 'last', 'cross_device_last_significant', 'automatic'];

    public static function assert(string $value): void
    {
        if (! in_array($value, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Недопустимая атрибуция. Доступны: ' . implode(', ', self::ALLOWED));
        }
    }
}
