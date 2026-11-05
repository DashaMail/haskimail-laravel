<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * @internal
 */
final class Time
{
    /** Haskimail returns timestamps as "YYYY-MM-DD HH:MM:SS" in Moscow time. */
    public const API_TIMEZONE = 'Europe/Moscow';

    public static function parseMoscow(mixed $value): ?CarbonImmutable
    {
        return self::parse($value, self::API_TIMEZONE);
    }

    public static function parseUtc(mixed $value): ?CarbonImmutable
    {
        return self::parse($value, 'UTC');
    }

    private static function parse(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
